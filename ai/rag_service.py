import os
import re
import logging
from typing import Dict, Any, List, Optional
import chromadb
from chromadb import Documents, EmbeddingFunction, Embeddings
import ollama

logger = logging.getLogger("DiaWell.RAGService")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CHROMA_DB_DIR = os.path.join(BASE_DIR, "chroma_db")
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://127.0.0.1:11434")
EMBEDDING_MODEL = os.getenv("OLLAMA_EMBED_MODEL", "nomic-embed-text:latest")


class OllamaEmbeddingFunction(EmbeddingFunction):
    """Local Ollama embedding function for vector query encoding."""
    def __init__(self, model_name: str = EMBEDDING_MODEL, host: str = OLLAMA_HOST):
        self.model_name = model_name
        self.client = ollama.Client(host=host)

    def __call__(self, input: Documents) -> Embeddings:
        if not input:
            return []
        try:
            res = self.client.embed(model=self.model_name, input=input)
            return res.get("embeddings", [])
        except Exception as e:
            logger.error(f"Error in Ollama query embedding ({self.model_name}): {e}")
            raise RuntimeError(f"Ollama query embedding failed: {e}")


class RAGService:
    def __init__(self, chroma_dir: str = CHROMA_DB_DIR, host: str = OLLAMA_HOST):
        self.chroma_dir = chroma_dir
        self.host = host
        self.embedding_fn = OllamaEmbeddingFunction(host=host)
        self._client: Optional[chromadb.PersistentClient] = None
        self._collections: Dict[str, Any] = {}

    def get_client(self) -> chromadb.PersistentClient:
        if self._client is None:
            os.makedirs(self.chroma_dir, exist_ok=True)
            self._client = chromadb.PersistentClient(path=self.chroma_dir)
        return self._client

    def get_collection(self, name: str):
        if name not in self._collections:
            client = self.get_client()
            try:
                self._collections[name] = client.get_collection(
                    name=name,
                    embedding_function=self.embedding_fn
                )
            except Exception as e:
                logger.warning(f"Could not load collection '{name}': {e}")
                return None
        return self._collections.get(name)

    def classify_query(self, query: str) -> str:
        """
        Identify whether a query is about clinical education, medicine, activities, food, or general conversation.
        """
        q = query.lower()

        # 1. Greetings and general chit-chat
        general_patterns = [
            r"^(hi|hello|hey|good\s*(morning|afternoon|evening)|howdy)\b",
            r"^who are you",
            r"^what can you do",
            r"^thank(s|\s*you)",
            r"^how are you"
        ]
        for pat in general_patterns:
            if re.search(pat, q.strip()):
                return "general"

        # 2. Physical Activity / Exercise queries (Checked before general clinical to handle "exercise with 150 sugar")
        activity_keywords = [
            "walk", "walking", "run", "running", "jog", "jogging", "cycle", "cycling",
            "bicycling", "bicycle", "exercise", "workout", "cardio", "met value",
            "metabolic equivalent", "intensity", "brisk walk", "aerobic", "steps",
            "treadmill", "calories burned", "calorie burn"
        ]
        if any(k in q for k in activity_keywords):
            return "activity"

        # 3. Clinical / Blood Glucose Emergency / Lab Values
        clinical_keywords = [
            "hypoglycemia", "hyperglycemia", "hba1c", "blood sugar", "glucose reading",
            "target range", "fasting sugar", "postprandial", "ketones", "cgm",
            "glucometer", "sensor", "low sugar", "high sugar", "sugar level", "mg/dl", "mgdl",
            "sugar is", "sugar dropped", "sugar spiked", "diabetic ketoacidosis"
        ]
        if any(k in q for k in clinical_keywords) or re.search(r"sugar\s*(is)?\s*\d+", q):
            return "clinical"

        # 4. Medicine / Drug queries
        med_keywords = [
            "medicine", "medication", "drug", "tablet", "capsule", "syrup", "injection",
            "insulin", "metformin", "glimepiride", "vildagliptin", "sitagliptin", "dapagliflozin",
            "empagliflozin", "amaryl", "glycomet", "januvia", "galvus", "forxiga", "jardiance",
            "dose", "dosage", "salt", "active ingredient", "side effect", "pill"
        ]
        if any(k in q for k in med_keywords):
            return "medicine"
        food_keywords = [
            "food", "eat", "eating", "snack", "breakfast", "lunch", "dinner", "carbs", "carbohydrate",
            "calorie", "calories", "protein", "fiber", "fibre", "diet", "dish",
            "recipe", "dal", "curry", "rice", "roti", "paratha", "chilla", "idli", "dosa",
            "paneer", "egg", "chicken", "salad", "fruit", "banana", "oats", "glycemic index",
            "nutrition", "soup", "vegetable", "sweet", "dessert", "juice"
        ]
        if any(k in q for k in food_keywords) or "sugar in" in q:
            return "food"

        return "general"

    def retrieve_context(self, query: str, n_results: int = 3) -> Dict[str, Any]:
        """
        Classifies the query and retrieves the most relevant verified records from ChromaDB.
        Returns retrieved context, source metadata, and category.
        """
        category = self.classify_query(query)
        logger.info(f"Classified query '{query[:50]}...' as: '{category}'")

        if category == "general":
            return {
                "category": "general",
                "collection_name": None,
                "has_context": False,
                "context_text": "",
                "sources": []
            }

        # Map category to collection name
        col_map = {
            "food": "diawell_food_nutrition",
            "activity": "diawell_physical_activities",
            "medicine": "diawell_diabetes_meds",
            "clinical": "diawell_clinical_guidelines"
        }

        col_name = col_map.get(category)
        collection = self.get_collection(col_name)

        if collection is None:
            logger.warning(f"Collection '{col_name}' is not accessible.")
            return {
                "category": category,
                "collection_name": col_name,
                "has_context": False,
                "context_text": "",
                "sources": []
            }

        # Check if collection is empty (e.g. diawell_clinical_guidelines is empty per Task 4)
        count = collection.count()
        if count == 0:
            logger.info(f"Collection '{col_name}' has 0 records. Proceeding without fabricated context.")
            return {
                "category": category,
                "collection_name": col_name,
                "has_context": False,
                "context_text": "",
                "sources": []
            }

        try:
            # Smart Nutritional Constraint Handling for Food Queries
            q_lower = query.lower()
            q_norm = q_lower.replace("-", " ")
            where_filter = None
            requested_nutritional_constraint = None

            if category == "food":
                high_fiber_keywords = [
                    "high fiber", "high fibre", "rich in fiber", "rich in fibre",
                    "fiber rich", "fibre rich", "high in fiber", "high in fibre",
                    "more fiber", "more fibre", "highest fiber", "highest fibre",
                    "source of fiber", "source of fibre", "good fiber", "good fibre"
                ]
                if any(k in q_norm for k in high_fiber_keywords):
                    # Strict project rule: at least 3.0g fibre per 100g
                    where_filter = {"fibre_g": {"$gte": 3.0}}
                    requested_nutritional_constraint = "high_fiber"
                elif any(k in q_norm for k in ["low carb", "low carbohydrate", "keto"]):
                    where_filter = {"carbs_g": {"$lte": 15.0}}
                    requested_nutritional_constraint = "low_carb"
                elif any(k in q_norm for k in ["low calorie", "low cal", "weight loss"]):
                    where_filter = {"calories": {"$lte": 180.0}}
                    requested_nutritional_constraint = "low_calorie"

            # Execute query: fetch extra candidates when filtered to allow secondary filtering
            results = None
            fetch_k = min(count, max(n_results * 4, 15)) if requested_nutritional_constraint else min(n_results, count)

            if where_filter:
                try:
                    results = collection.query(
                        query_texts=[query],
                        n_results=fetch_k,
                        where=where_filter
                    )
                except Exception as fe:
                    logger.warning(f"Filter query error ({where_filter}): {fe}. Proceeding without unfiltered fallback.")
                    results = None

            # Fallback only when NO nutritional constraint was requested
            if results is None and where_filter is None:
                results = collection.query(
                    query_texts=[query],
                    n_results=min(n_results, count)
                )

            docs = results.get("documents", [[]])[0] if results else []
            metadatas = results.get("metadatas", [[]])[0] if results else []
            distances = results.get("distances", [[]])[0] if (results and "distances" in results) else []

            # Secondary strict Python-level safety filter
            valid_candidates = []
            for i, doc in enumerate(docs):
                meta = metadatas[i] if i < len(metadatas) else {}
                dist = distances[i] if i < len(distances) else None

                # Discard low-relevance matches if distance is excessively high
                if dist is not None and dist > 1.4:
                    continue

                # Strict High-Fiber Rule: Fibre must be >= 3.0g per 100g
                if requested_nutritional_constraint == "high_fiber":
                    fib_val = float(meta.get("fibre_g", 0.0) or 0.0)
                    if fib_val < 3.0:
                        continue
                    # Exclude non-meal spice powders and ultra-sugary confections when foods are requested
                    name_lower = meta.get("dish_name", "").lower()
                    sodium = float(meta.get("sodium_mg", 0.0) or 0.0)
                    sugar = float(meta.get("free_sugar_g", 0.0) or 0.0)
                    if any(sp in name_lower for sp in ["spice blend", "masala powder", "panch phoran"]) or sodium > 1500:
                        continue
                    if "burfi" in name_lower or "fudge" in name_lower or sugar > 20.0:
                        continue

                elif requested_nutritional_constraint == "low_carb":
                    carbs_val = float(meta.get("carbs_g", 999.0) or 999.0)
                    if carbs_val > 15.0:
                        continue

                elif requested_nutritional_constraint == "low_calorie":
                    cal_val = float(meta.get("calories", 999.0) or 999.0)
                    if cal_val > 180.0:
                        continue

                valid_candidates.append((doc, meta))

            # Cap to requested count
            final_candidates = valid_candidates[:n_results]

            if not final_candidates:
                if requested_nutritional_constraint == "high_fiber":
                    return {
                        "category": category,
                        "collection_name": col_name,
                        "has_context": True,
                        "context_text": (
                            "[STRICT NUTRITIONAL AUDIT: Exactly 0 verified food(s) in the dataset met the high-fiber threshold (>= 3.0g fibre per 100g). "
                            "You MUST inform the user that no verified dishes in the dataset met the >= 3.0g/100g high-fiber threshold. "
                            "NEVER recommend or label any food below 3.0g fibre as high-fiber, and NEVER invent unverified foods.]"
                        ),
                        "sources": []
                    }
                else:
                    logger.info(f"No matching documents returned from collection '{col_name}'.")
                    return {
                        "category": category,
                        "collection_name": col_name,
                        "has_context": False,
                        "context_text": "",
                        "sources": []
                    }

            context_items = []
            sources = []
            for doc, meta in final_candidates:
                context_items.append(f"• {doc}")
                source_name = meta.get("source", col_name)
                title = meta.get("dish_name") or meta.get("brand_name") or meta.get("description") or "Dataset Record"
                sources.append({
                    "title": title,
                    "source": source_name,
                    "category": category,
                    "metadata": meta
                })

            context_text = "\n".join(context_items)

            # Audit notice for High-Fiber Constraint
            if requested_nutritional_constraint == "high_fiber":
                matched_count = len(final_candidates)
                if matched_count < n_results:
                    context_text += (
                        f"\n\n[STRICT NUTRITIONAL AUDIT: The user requested {n_results} high-fibre foods, but exactly {matched_count} verified food(s) in the dataset met the strict threshold (>= 3.0g fibre per 100g). "
                        f"You MUST recommend ONLY these {matched_count} matching food(s) and explicitly explain to the user that only {matched_count} verified dish(es) met the >= 3.0g/100g threshold. "
                        "Do NOT invent or suggest ANY extra foods with < 3.0g fibre to fill the requested count.]"
                    )
                else:
                    context_text += (
                        "\n\n[STRICT NUTRITIONAL AUDIT: All recommended dishes above have AT LEAST 3.0g fibre per 100g edible portion strictly verified from the dataset. "
                        "State their exact nutritional values per 100g from the verified information. "
                        "NEVER recommend or label any food with less than 3.0g fibre (such as Dal dhokli: 1.86g or Dahi vadas: 0.97g) as high-fibre.]"
                    )

            # Append safety reminder for medicine
            if category == "medicine":
                context_text += "\n[SAFETY NOTICE: Prescription information is for reference only. Never recommend starting, stopping, or altering a medication or dosage. Always instruct the user to consult a doctor.]"

            # Append clinical safety guidelines for physical activities
            if category == "activity":
                context_text += (
                    "\n[CLINICAL EXERCISE SAFETY GUIDELINES:\n"
                    "• Blood glucose < 70 mg/dL: Hypoglycemia. Exercise is strictly contraindicated. Immediately consume 15–20g fast-acting carbohydrates and re-test in 15 minutes.\n"
                    "• Blood glucose 70–99 mg/dL: Hypoglycemia risk. Consume a 15g carbohydrate snack before exercise.\n"
                    "• Blood glucose 100–249 mg/dL: Safe pre-exercise target window.\n"
                    "• Blood glucose 250–299 mg/dL: Elevated glucose. Vigorous workouts and running are contraindicated; only light walking is permitted if ketones are negative.\n"
                    "• Blood glucose >= 300 mg/dL: Severe hyperglycemia. Exercise is strictly contraindicated due to DKA and dehydration risks.\n"
                    "• Calorie estimation formula: Calories = MET × Weight (kg) × (Duration in mins / 60). Clearly label as an estimate.\n"
                    "• Disclaimer: Exercise guidance is for lifestyle support only and does not substitute for medical treatment or clinical prescription.]"
                )

            return {
                "category": category,
                "collection_name": col_name,
                "has_context": True,
                "context_text": context_text,
                "sources": sources
            }

        except Exception as e:
            logger.error(f"Error querying ChromaDB collection '{col_name}': {e}")
            return {
                "category": category,
                "collection_name": col_name,
                "has_context": False,
                "context_text": "",
                "sources": [],
                "error": str(e)
            }

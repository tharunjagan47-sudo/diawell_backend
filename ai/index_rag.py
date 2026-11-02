import os
import sys
import time
import argparse
import logging
from typing import List, Dict, Any, Optional
import pandas as pd
import chromadb
from chromadb import Documents, EmbeddingFunction, Embeddings
import ollama

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("DiaWell.IndexRAG")

# Path constants
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
BACKEND_DIR = os.path.dirname(BASE_DIR)
CHROMA_DB_DIR = os.path.join(BASE_DIR, "chroma_db")

FOOD_CSV_PATH = os.path.join(BACKEND_DIR, "data", "Indian_Food_Nutrition_Processed.csv")
ACTIVITY_CSV_PATH = os.path.join(BACKEND_DIR, "activity dataset", "walking_running_cycling.csv")
MEDS_CSV_PATH = os.path.join(BACKEND_DIR, "meds_common.csv")

OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://127.0.0.1:11434")
EMBEDDING_MODEL = os.getenv("OLLAMA_EMBED_MODEL", "nomic-embed-text:latest")


class OllamaEmbeddingFunction(EmbeddingFunction):
    """
    ChromaDB EmbeddingFunction using local Ollama model (nomic-embed-text:latest).
    Uses batch processing for high indexing throughput.
    """
    def __init__(self, model_name: str = EMBEDDING_MODEL, host: str = OLLAMA_HOST):
        self.model_name = model_name
        self.client = ollama.Client(host=host)

    def __call__(self, input: Documents) -> Embeddings:
        if not input:
            return []
        try:
            # Batch embedding call supported by modern Ollama
            res = self.client.embed(model=self.model_name, input=input)
            return res.get("embeddings", [])
        except Exception as e:
            logger.error(f"Error generating batch embeddings via Ollama ({self.model_name}): {e}")
            raise RuntimeError(
                f"Ollama embedding failed. Make sure Ollama is running and '{self.model_name}' is installed: {e}"
            )


def verify_ollama_ready(host: str = OLLAMA_HOST, model: str = EMBEDDING_MODEL):
    """Verify that the local Ollama instance is running and has nomic-embed-text installed."""
    try:
        client = ollama.Client(host=host)
        models_resp = client.list()
        installed = []
        for m in getattr(models_resp, "models", []):
            name = getattr(m, "model", None) or getattr(m, "name", None)
            if not name and isinstance(m, dict):
                name = m.get("model") or m.get("name")
            if name:
                installed.append(str(name))

        matching = [m for m in installed if model.split(":")[0] in m]
        if not matching:
            logger.warning(
                f"Embedding model '{model}' not found in installed models: {installed}. "
                f"Please run 'ollama pull {model}'."
            )
        else:
            logger.info(f"Ollama embedding model '{model}' verified on {host}.")
        return True
    except Exception as e:
        logger.error(f"Cannot connect to Ollama at {host}: {e}")
        return False


def get_chroma_client() -> chromadb.PersistentClient:
    """Initialize or connect to the persistent ChromaDB directory."""
    os.makedirs(CHROMA_DB_DIR, exist_ok=True)
    return chromadb.PersistentClient(path=CHROMA_DB_DIR)


# =============================================================================
# 1. Food Nutrition Indexer
# =============================================================================
def index_food_dataset(client: chromadb.PersistentClient, embedding_fn: EmbeddingFunction, batch_size: int = 50):
    """
    Index all 1,014 Indian dishes with actual macro and micronutrient values.
    Uses upsert to prevent duplicate records on re-indexing.
    """
    if not os.path.exists(FOOD_CSV_PATH):
        logger.error(f"Food dataset not found at: {FOOD_CSV_PATH}")
        return

    logger.info(f"Reading Food Nutrition dataset from: {FOOD_CSV_PATH}")
    df = pd.read_csv(FOOD_CSV_PATH)
    total_records = len(df)
    logger.info(f"Loaded {total_records} food dishes. Creating collection 'diawell_food_nutrition'...")

    collection = client.get_or_create_collection(
        name="diawell_food_nutrition",
        embedding_function=embedding_fn,
        metadata={"description": "Authentic Indian Food Nutrition dataset with calories, carbs, fiber and nutrients"}
    )

    ids = []
    documents = []
    metadatas = []

    for idx, row in df.iterrows():
        dish_name = str(row.get("Dish Name", "")).strip()
        if not dish_name:
            continue

        cals = float(row.get("Calories (kcal)", 0.0) or 0.0)
        carbs = float(row.get("Carbohydrates (g)", 0.0) or 0.0)
        pro = float(row.get("Protein (g)", 0.0) or 0.0)
        fat = float(row.get("Fats (g)", 0.0) or 0.0)
        sugar = float(row.get("Free Sugar (g)", 0.0) or 0.0)
        fiber = float(row.get("Fibre (g)", 0.0) or 0.0)
        sodium = float(row.get("Sodium (mg)", 0.0) or 0.0)

        # Build clean, search-friendly document text explicitly indicating per 100g basis
        doc_text = (
            f"Dish: {dish_name}. "
            f"Nutritional values (per 100g edible portion): Calories: {cals:.1f} kcal, "
            f"Carbohydrates: {carbs:.1f}g, Protein: {pro:.1f}g, Fats: {fat:.1f}g, "
            f"Free Sugar: {sugar:.1f}g, Dietary Fibre: {fiber:.1f}g, Sodium: {sodium:.1f}mg."
        )

        doc_id = f"food_{idx + 1}"
        meta = {
            "dish_name": dish_name,
            "calories": cals,
            "carbs_g": carbs,
            "protein_g": pro,
            "fats_g": fat,
            "free_sugar_g": sugar,
            "fibre_g": fiber,
            "sodium_mg": sodium,
            "portion_basis": "100g edible portion",
            "source": "Indian Food Nutrition Dataset"
        }

        ids.append(doc_id)
        documents.append(doc_text)
        metadatas.append(meta)

    # Batch upsert
    total_batches = (len(ids) + batch_size - 1) // batch_size
    logger.info(f"Upserting {len(ids)} food records in {total_batches} batches (batch size {batch_size})...")

    t0 = time.time()
    for b_idx in range(0, len(ids), batch_size):
        b_ids = ids[b_idx:b_idx + batch_size]
        b_docs = documents[b_idx:b_idx + batch_size]
        b_metas = metadatas[b_idx:b_idx + batch_size]

        collection.upsert(ids=b_ids, documents=b_docs, metadatas=b_metas)
        current_batch = (b_idx // batch_size) + 1
        if current_batch % 5 == 0 or current_batch == total_batches:
            logger.info(f"  Food Batch {current_batch}/{total_batches} ({min(b_idx + batch_size, len(ids))}/{len(ids)}) complete.")

    logger.info(f"Food indexing complete! Total in collection: {collection.count()} items ({time.time() - t0:.1f}s).")


# =============================================================================
# 2. Physical Activities Indexer
# =============================================================================
def index_activities_dataset(client: chromadb.PersistentClient, embedding_fn: EmbeddingFunction, batch_size: int = 50):
    """
    Index all 203 physical activity records with MET values and intensity categories.
    Uses upsert to prevent duplicate records.
    """
    if not os.path.exists(ACTIVITY_CSV_PATH):
        logger.error(f"Activity dataset not found at: {ACTIVITY_CSV_PATH}")
        return

    logger.info(f"Reading Physical Activity dataset from: {ACTIVITY_CSV_PATH}")
    df = pd.read_csv(ACTIVITY_CSV_PATH)
    total_records = len(df)
    logger.info(f"Loaded {total_records} activities. Creating collection 'diawell_physical_activities'...")

    collection = client.get_or_create_collection(
        name="diawell_physical_activities",
        embedding_function=embedding_fn,
        metadata={"description": "2024 Adult Compendium of Physical Activities with MET intensity values"}
    )

    ids = []
    documents = []
    metadatas = []

    for idx, row in df.iterrows():
        cat = str(row.get("activity_category", "")).strip()
        code = int(row.get("activity_code", 0) or 0)
        met = float(row.get("met_value", 0.0) or 0.0)
        desc = str(row.get("activity_description", "")).strip()

        # Categorize intensity
        if met < 3.0:
            intensity = "Light"
        elif met < 6.0:
            intensity = "Moderate"
        else:
            intensity = "Vigorous"

        doc_text = (
            f"Physical Activity: {desc}. "
            f"Category: {cat}. "
            f"Intensity Level: {intensity} ({met:.1f} METs). "
            f"Compendium Code: {code}."
        )

        doc_id = f"activity_{code}_{idx + 1}"
        meta = {
            "activity_code": code,
            "category": cat,
            "met_value": met,
            "intensity": intensity,
            "description": desc,
            "source": "2024 Adult Compendium (Walking, Running, Cycling)"
        }

        ids.append(doc_id)
        documents.append(doc_text)
        metadatas.append(meta)

    total_batches = (len(ids) + batch_size - 1) // batch_size
    logger.info(f"Upserting {len(ids)} activities in {total_batches} batches...")

    t0 = time.time()
    for b_idx in range(0, len(ids), batch_size):
        b_ids = ids[b_idx:b_idx + batch_size]
        b_docs = documents[b_idx:b_idx + batch_size]
        b_metas = metadatas[b_idx:b_idx + batch_size]

        collection.upsert(ids=b_ids, documents=b_docs, metadatas=b_metas)

    logger.info(f"Activity indexing complete! Total in collection: {collection.count()} items ({time.time() - t0:.1f}s).")


# =============================================================================
# 3. Diabetes Medications Indexer
# =============================================================================
def index_medicines_dataset(
    client: chromadb.PersistentClient,
    embedding_fn: EmbeddingFunction,
    max_records: int = 2500,
    batch_size: int = 50
):
    """
    Filter and index diabetes-related medications (therapeutic_class: antidiabetic).
    Filters out the other 63,000 non-diabetic records to save memory and avoid search noise.
    Preserves brand name, active ingredients, strength, dosage form and manufacturer.
    """
    if not os.path.exists(MEDS_CSV_PATH):
        logger.error(f"Medicines dataset not found at: {MEDS_CSV_PATH}")
        return

    logger.info(f"Reading Medications dataset from: {MEDS_CSV_PATH} (filtering antidiabetic class)...")
    
    # Read only required columns
    cols = [
        "product_id", "brand_name", "manufacturer", "dosage_form",
        "primary_ingredient", "primary_strength", "active_ingredients", "therapeutic_class"
    ]
    df = pd.read_csv(MEDS_CSV_PATH, usecols=cols)
    
    # Filter strictly for diabetes medications
    antidiab_df = df[df["therapeutic_class"].str.lower() == "antidiabetic"].copy()
    total_antidiabetic = len(antidiab_df)
    logger.info(f"Found {total_antidiabetic} antidiabetic medication records (out of {len(df):,} total medicines).")

    # Deduplicate slightly if needed, or apply limit
    if max_records > 0 and total_antidiabetic > max_records:
        logger.info(f"Indexing the first {max_records} diverse antidiabetic medications (use --all-meds to index all {total_antidiabetic}).")
        target_df = antidiab_df.head(max_records)
    else:
        target_df = antidiab_df

    collection = client.get_or_create_collection(
        name="diawell_diabetes_meds",
        embedding_function=embedding_fn,
        metadata={"description": "Common Antidiabetic Medications with brand names, salts, and strengths"}
    )

    ids = []
    documents = []
    metadatas = []

    for _, row in target_df.iterrows():
        pid = int(row.get("product_id", 0) or 0)
        brand = str(row.get("brand_name", "")).strip()
        ingr = str(row.get("primary_ingredient", "")).strip()
        strength = str(row.get("primary_strength", "")).strip()
        dosage = str(row.get("dosage_form", "")).strip()
        mfg = str(row.get("manufacturer", "")).strip()
        act_ingr = str(row.get("active_ingredients", "")).strip()
        t_class = str(row.get("therapeutic_class", "antidiabetic")).strip()

        doc_text = (
            f"Antidiabetic Medicine: {brand}. "
            f"Primary Active Ingredient: {ingr} ({strength}). "
            f"Dosage Form: {dosage}. "
            f"Therapeutic Class: {t_class}. "
            f"Active Ingredients details: {act_ingr}. "
            f"Manufacturer: {mfg}."
        )

        doc_id = f"med_{pid}"
        meta = {
            "product_id": pid,
            "brand_name": brand,
            "primary_ingredient": ingr,
            "primary_strength": strength,
            "dosage_form": dosage,
            "therapeutic_class": t_class,
            "manufacturer": mfg,
            "source": "Common Medications Catalog (Antidiabetic)"
        }

        ids.append(doc_id)
        documents.append(doc_text)
        metadatas.append(meta)

    total_batches = (len(ids) + batch_size - 1) // batch_size
    logger.info(f"Upserting {len(ids)} antidiabetic medicines in {total_batches} batches (batch size {batch_size})...")

    t0 = time.time()
    for b_idx in range(0, len(ids), batch_size):
        b_ids = ids[b_idx:b_idx + batch_size]
        b_docs = documents[b_idx:b_idx + batch_size]
        b_metas = metadatas[b_idx:b_idx + batch_size]

        collection.upsert(ids=b_ids, documents=b_docs, metadatas=b_metas)
        current_batch = (b_idx // batch_size) + 1
        if current_batch % 10 == 0 or current_batch == total_batches:
            logger.info(f"  Meds Batch {current_batch}/{total_batches} ({min(b_idx + batch_size, len(ids))}/{len(ids)}) complete.")

    logger.info(f"Medicines indexing complete! Total in collection: {collection.count()} items ({time.time() - t0:.1f}s).")


# =============================================================================
# 4. Clinical Guidelines Collection Initializer
# =============================================================================
def initialize_clinical_collection(client: chromadb.PersistentClient, embedding_fn: EmbeddingFunction):
    """
    Create the 'diawell_clinical_guidelines' collection.
    As per Task 4, no fabricated ADA/ICMR documents are added; left empty until verified guidelines are provided.
    """
    collection = client.get_or_create_collection(
        name="diawell_clinical_guidelines",
        embedding_function=embedding_fn,
        metadata={"description": "Verified Clinical Diabetes Management Guidelines and Emergency Protocols"}
    )
    logger.info(f"Initialized collection 'diawell_clinical_guidelines' (Current count: {collection.count()} items).")


# =============================================================================
# Main Entry Point
# =============================================================================
def main():
    parser = argparse.ArgumentParser(description="DiaWell RAG Knowledge Base Indexer")
    parser.add_argument("--all", action="store_true", help="Index all datasets (food, activities, meds) and initialize clinical collection")
    parser.add_argument("--food", action="store_true", help="Index food nutrition dataset")
    parser.add_argument("--activity", action="store_true", help="Index physical activities dataset")
    parser.add_argument("--meds", action="store_true", help="Index antidiabetic medications")
    parser.add_argument("--max-meds", type=int, default=2500, help="Maximum antidiabetic records to index (default: 2500, use 0 for all)")
    parser.add_argument("--all-meds", action="store_true", help="Index all 8,644 antidiabetic medications without limit")
    parser.add_argument("--batch-size", type=int, default=50, help="Batch size for embeddings (default: 50)")
    parser.add_argument("--stats", action="store_true", help="Show current collection statistics in ChromaDB")
    
    args = parser.parse_args()

    # If no flags passed, default to --all
    if not (args.all or args.food or args.activity or args.meds or args.stats):
        args.all = True

    logger.info("=================================================================")
    logger.info(" Starting DiaWell RAG Knowledge Indexing System")
    logger.info(f" Persistent Vector DB Path: {CHROMA_DB_DIR}")
    logger.info(f" Ollama Embedding Model:    {EMBEDDING_MODEL}")
    logger.info("=================================================================")

    if not verify_ollama_ready():
        logger.error("Aborting indexing because Ollama embedding model is not reachable.")
        sys.exit(1)

    embedding_fn = OllamaEmbeddingFunction()
    client = get_chroma_client()

    if args.stats:
        logger.info("\n=== Current ChromaDB Collections Status ===")
        for name in ["diawell_food_nutrition", "diawell_physical_activities", "diawell_diabetes_meds", "diawell_clinical_guidelines"]:
            try:
                col = client.get_collection(name=name, embedding_function=embedding_fn)
                logger.info(f"  • {name}: {col.count()} documents")
            except Exception:
                logger.info(f"  • {name}: Not initialized yet")
        return

    # Index food
    if args.all or args.food:
        index_food_dataset(client, embedding_fn, batch_size=args.batch_size)

    # Index activities
    if args.all or args.activity:
        index_activities_dataset(client, embedding_fn, batch_size=args.batch_size)

    # Index medications
    if args.all or args.meds:
        meds_limit = 0 if args.all_meds else args.max_meds
        index_medicines_dataset(client, embedding_fn, max_records=meds_limit, batch_size=args.batch_size)

    # Initialize clinical guidelines collection (empty per Task 4)
    if args.all:
        initialize_clinical_collection(client, embedding_fn)

    logger.info("\n=== All Indexing Tasks Complete! Final Collection Stats ===")
    for name in ["diawell_food_nutrition", "diawell_physical_activities", "diawell_diabetes_meds", "diawell_clinical_guidelines"]:
        try:
            col = client.get_collection(name=name, embedding_function=embedding_fn)
            logger.info(f"  • {name}: {col.count()} documents")
        except Exception as e:
            logger.warning(f"  • {name}: {e}")


if __name__ == "__main__":
    main()


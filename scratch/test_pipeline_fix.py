import sys
import re
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService
from ollama_service import OllamaService

rag = RAGService()
ollama_svc = OllamaService()

def simulate_retrieve(query, n_results=3):
    q_norm = query.lower().replace("-", " ")
    high_fiber_keywords = [
        "high fiber", "high fibre", "rich in fiber", "rich in fibre",
        "fiber rich", "fibre rich", "high in fiber", "high in fibre",
        "more fiber", "more fibre", "highest fiber", "highest fibre"
    ]
    is_hf = any(k in q_norm for k in high_fiber_keywords)

    col = rag.get_collection("diawell_food_nutrition")
    where_filter = {"fibre_g": {"$gte": 3.0}} if is_hf else None

    # Fetch extra candidates when filtered
    fetch_k = min(col.count(), max(n_results * 3, 12)) if where_filter else min(n_results, col.count())

    results = col.query(
        query_texts=[query],
        n_results=fetch_k,
        where=where_filter
    )

    docs = results["documents"][0]
    metas = results["metadatas"][0]

    def is_edible_dish(meta):
        name = meta.get("dish_name", "").lower()
        sodium = float(meta.get("sodium_mg", 0) or 0)
        sugar = float(meta.get("free_sugar_g", 0) or 0)
        # Filter out dry raw spice powders and excessively sugary sweets (e.g. burfi with 31g sugar)
        if any(w in name for w in ["spice blend", "masala powder", "panch phoran"]) or sodium > 1500:
            return False
        if "burfi" in name or "fudge" in name or sugar > 20.0:
            return False
        return True

    candidates = []
    for d, m in zip(docs, metas):
        if is_hf:
            fib_val = float(m.get("fibre_g", 0) or 0)
            if fib_val < 3.0 or not is_edible_dish(m):
                continue
        candidates.append((d, m))

    final_candidates = candidates[:n_results]

    context_items = [f"• {d}" for d, _ in final_candidates]
    sources = [{"title": m.get("dish_name"), "source": m.get("source"), "metadata": m} for _, m in final_candidates]

    if is_hf:
        matched_count = len(final_candidates)
        if matched_count == 0:
            context_text = (
                "[DATASET HIGH-FIBER AUDIT: Exactly 0 foods in the verified dataset met the strict high-fiber threshold (>= 3.0g fibre per 100g). "
                "You MUST inform the user that no verified dishes in the dataset met the >= 3.0g/100g requirement. "
                "NEVER recommend foods below 3.0g fibre as high-fiber, and NEVER invent unverified foods.]"
            )
        elif matched_count < n_results:
            context_text = "\n".join(context_items) + (
                f"\n\n[DATASET HIGH-FIBER AUDIT: Exactly {matched_count} verified food(s) in the dataset met the strict high-fiber threshold (>= 3.0g fibre per 100g). "
                f"You MUST recommend ONLY these {matched_count} matching food(s) and explicitly explain that only {matched_count} verified dish(es) met the >= 3.0g/100g threshold in the dataset. "
                "Do NOT invent or suggest ANY extra foods with < 3.0g fibre to fill the requested count.]"
            )
        else:
            context_text = "\n".join(context_items) + (
                "\n\n[DATASET HIGH-FIBER AUDIT: All recommended dishes above have AT LEAST 3.0g fibre per 100g edible portion strictly verified from the dataset. "
                "State their exact nutritional values per 100g from the verified information. "
                "NEVER recommend or label any food with less than 3.0g fibre (such as Dal dhokli: 1.86g or Dahi vadas: 0.97g) as high-fibre.]"
            )
    else:
        context_text = "\n".join(context_items)

    return {
        "context_text": context_text,
        "sources": sources
    }

query = "3 high-fibre Indian foods"
ret = simulate_retrieve(query, n_results=3)
print("Context:\n", ret["context_text"])
print("\nSources:")
for s in ret["sources"]:
    m = s["metadata"]
    print(f"  • {m['dish_name']} | Fibre: {m['fibre_g']}g | Cals: {m['calories']} | Carbs: {m['carbs_g']}g")

resp = ollama_svc.generate_chat_response(
    message=query,
    rag_context=ret["context_text"],
    sources=ret["sources"]
)

print("\n--- Qwen Response ---")
print(resp["response"])

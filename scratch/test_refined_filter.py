import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService

rag = RAGService()
col = rag.get_collection('diawell_food_nutrition')

# Query ChromaDB with fibre >= 3.0
results = col.query(
    query_texts=['Suggest 3 high-fibre Indian foods'],
    n_results=10,
    where={'fibre_g': {'$gte': 3.0}}
)

docs = results['documents'][0]
metas = results['metadatas'][0]

print(f"Total retrieved from ChromaDB: {len(docs)}")

# Filter out non-meal spice powders/masalas if user asks for foods
def is_edible_dish(meta):
    name = meta.get("dish_name", "").lower()
    sodium = float(meta.get("sodium_mg", 0) or 0)
    if "spice blend" in name or "masala" in name or sodium > 1500:
        return False
    return True

dishes = [(d, m) for d, m in zip(docs, metas) if is_edible_dish(m) and float(m.get("fibre_g", 0)) >= 3.0]

print(f"Edible dishes with fibre >= 3.0g: {len(dishes)}")
for d, m in dishes[:5]:
    print(f"  • {m['dish_name']} | Fibre: {m['fibre_g']}g | Cals: {m['calories']} kcal | Carbs: {m['carbs_g']}g")


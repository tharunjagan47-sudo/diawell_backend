import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService

rag = RAGService()
col = rag.get_collection('diawell_food_nutrition')
print('diawell_food_nutrition count:', col.count())

res = col.query(
    query_texts=['3 high-fibre Indian foods'],
    n_results=10,
    where={'fibre_g': {'$gte': 3.0}}
)

docs = res['documents'][0]
metas = res['metadatas'][0]
print(f"Found {len(docs)} matching dishes with fibre >= 3.0g:")
for m in metas:
    print(f"  • {m['dish_name']}: {m['fibre_g']}g fibre, {m['calories']} kcal, {m['carbs_g']}g carbs")


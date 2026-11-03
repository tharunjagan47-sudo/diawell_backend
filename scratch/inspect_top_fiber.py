import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService

rag = RAGService()
col = rag.get_collection('diawell_food_nutrition')

res = col.query(
    query_texts=['3 high-fibre Indian foods'],
    n_results=10,
    where={'fibre_g': {'$gte': 3.0}}
)

for doc, meta in zip(res['documents'][0], res['metadatas'][0]):
    name = meta['dish_name']
    fib = meta['fibre_g']
    cal = meta['calories']
    carb = meta['carbs_g']
    pro = meta['protein_g']
    fat = meta['fats_g']
    print(f"Dish: '{name}' | Fibre: {fib}g | Cal: {cal} | Carb: {carb}g | Pro: {pro}g | Fat: {fat}g")


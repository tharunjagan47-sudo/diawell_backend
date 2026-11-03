import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService

rag = RAGService()
queries = [
    "3 high-fibre Indian foods",
    "3 high-fiber Indian foods",
    "high-fibre Indian foods",
    "high-fiber Indian foods",
    "three high-fibre Indian foods",
    "Suggest 3 high-fibre Indian foods",
    "Can you give me 3 high-fibre Indian foods",
    "Indian foods high in fibre",
    "Give me 3 high-fibre Indian snacks"
]

for q in queries:
    ret = rag.retrieve_context(q)
    dishes = [f"{s['title']} ({s['metadata'].get('fibre_g')}g)" for s in ret['sources']]
    print(f"Query: '{q}' -> {dishes}")


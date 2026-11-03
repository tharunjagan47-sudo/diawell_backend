import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from rag_service import RAGService

rag = RAGService()
ret = rag.retrieve_context("3 high-fibre Indian foods")
print("Category:", ret["category"])
print("Has context:", ret["has_context"])
print("Sources count:", len(ret["sources"]))
for s in ret["sources"]:
    meta = s["metadata"]
    print(f"  • {s['title']}: {meta.get('fibre_g')}g fibre, {meta.get('calories')} kcal, {meta.get('carbs_g')}g carbs")

print("\nContext text:\n", ret["context_text"])


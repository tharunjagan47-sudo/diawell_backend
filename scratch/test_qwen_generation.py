import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from ollama_service import OllamaService
from rag_service import RAGService

rag = RAGService()
ollama_svc = OllamaService()

ret = rag.retrieve_context("Suggest 3 high-fibre Indian foods")
print("Retrieved sources count:", len(ret["sources"]))
for s in ret["sources"]:
    meta = s["metadata"]
    print(f"  • {s['title']}: {meta.get('fibre_g')}g fibre")

resp = ollama_svc.generate_chat_response(
    message="Suggest 3 high-fibre Indian foods",
    rag_context=ret["context_text"],
    sources=ret["sources"]
)

print("\n--- Qwen Response ---")
print(resp["response"])


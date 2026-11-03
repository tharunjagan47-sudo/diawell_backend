import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from ollama_service import OllamaService

ollama_svc = OllamaService()

context_text = """• Dish: Semolina dhokla (Suji/Rava dhokla). Nutritional values (per 100g edible portion): Calories: 194.9 kcal, Carbohydrates: 30.9g, Protein: 5.4g, Fats: 5.2g, Free Sugar: 1.9g, Dietary Fibre: 5.1g, Sodium: 443.4mg.

[STRICT HIGH-FIBER THRESHOLD (CRITICAL): The user requested 3 high-fibre foods, but ONLY 1 matching dish in the verified dataset meets the strict high-fiber threshold (>= 3.0g fibre per 100g). You MUST recommend ONLY this 1 matching dish and explicitly explain to the user that only 1 verified match was found in the dataset meeting the >= 3.0g/100g threshold. NEVER invent foods or add foods with < 3.0g fibre to reach 3.]"""

sources = [
    {"title": "Semolina dhokla (Suji/Rava dhokla)", "source": "Indian Food Nutrition Dataset"}
]

resp = ollama_svc.generate_chat_response(
    message="Suggest 3 high-fibre Indian foods",
    rag_context=context_text,
    sources=sources
)

print("Qwen Response (Fewer than 3 matches):\n", resp["response"])


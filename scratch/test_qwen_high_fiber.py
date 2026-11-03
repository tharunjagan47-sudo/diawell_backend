import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from ollama_service import OllamaService

ollama_svc = OllamaService()

context_text = """• Dish: Semolina dhokla (Suji/Rava dhokla). Nutritional values (per 100g edible portion): Calories: 194.9 kcal, Carbohydrates: 30.9g, Protein: 5.4g, Fats: 5.2g, Free Sugar: 1.9g, Dietary Fibre: 5.1g, Sodium: 443.4mg.
• Dish: Gram flour and semolina dhokla (Besan suji dhokla). Nutritional values (per 100g edible portion): Calories: 198.9 kcal, Carbohydrates: 25.9g, Protein: 8.5g, Fats: 7.1g, Free Sugar: 2.6g, Dietary Fibre: 4.6g, Sodium: 196.6mg.
• Dish: Maa chaane ki dal. Nutritional values (per 100g edible portion): Calories: 344.7 kcal, Carbohydrates: 43.1g, Protein: 19.8g, Fats: 9.5g, Free Sugar: 0.1g, Dietary Fibre: 13.5g, Sodium: 52.8mg.

[STRICT HIGH-FIBER THRESHOLD (CRITICAL): Under DiaWell clinical guidelines, ONLY foods with AT LEAST 3.0g of dietary fibre per 100g edible portion may be labeled or recommended as high-fibre. All listed dishes have >= 3.0g fibre per 100g. State their exact values per 100g. NEVER recommend or label any food below 3.0g fibre (such as Dal dhokli with 1.86g or Dahi vadas with 0.97g) as high-fibre.]"""

sources = [
    {"title": "Semolina dhokla (Suji/Rava dhokla)", "source": "Indian Food Nutrition Dataset"},
    {"title": "Gram flour and semolina dhokla (Besan suji dhokla)", "source": "Indian Food Nutrition Dataset"},
    {"title": "Maa chaane ki dal", "source": "Indian Food Nutrition Dataset"}
]

resp = ollama_svc.generate_chat_response(
    message="Suggest 3 high-fibre Indian foods",
    rag_context=context_text,
    sources=sources
)

print("Qwen Response:\n", resp["response"])


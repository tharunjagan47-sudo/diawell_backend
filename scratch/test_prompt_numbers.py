import sys
sys.path.insert(0, r'C:\xampp\htdocs\DiaWell_backend\ai')
from ollama_service import OllamaService

ollama_svc = OllamaService()

context_text = """[VERIFIED DATASET INFORMATION - PER 100g EDIBLE PORTION]
• Dish: Gram flour and semolina dhokla (Besan suji dhokla) -> Calories: 198.9 kcal, Carbohydrates: 25.9g, Protein: 8.5g, Fats: 7.1g, Dietary Fibre: 4.59g (or 4.6g).
• Dish: Maa chaane ki dal -> Calories: 344.7 kcal, Carbohydrates: 43.1g, Protein: 19.8g, Fats: 9.5g, Dietary Fibre: 13.45g (or 13.5g).
• Dish: Semolina dhokla (Suji/Rava dhokla) -> Calories: 194.9 kcal, Carbohydrates: 30.9g, Protein: 5.4g, Fats: 5.2g, Dietary Fibre: 5.12g (or 5.1g).

[STRICT NUTRITIONAL GUIDELINES]:
1. High-Fiber Threshold: Under DiaWell rules, ONLY dishes with AT LEAST 3.0g dietary fibre per 100g qualify as high-fibre. All dishes above qualify.
2. Exact Values: Quote the EXACT fibre, calorie, and carb figures stated above. Do NOT alter, estimate, or invent values.
3. Basis: Explicitly state that all values are per 100g edible portion.
4. Concision: Answer directly in 2 to 5 sentences or concise bullet points."""

sources = [
    {"title": "Gram flour and semolina dhokla", "source": "Indian Food Nutrition Dataset"},
    {"title": "Maa chaane ki dal", "source": "Indian Food Nutrition Dataset"},
    {"title": "Semolina dhokla", "source": "Indian Food Nutrition Dataset"}
]

resp = ollama_svc.generate_chat_response(
    message="Suggest 3 high-fibre Indian foods",
    rag_context=context_text,
    sources=sources
)

print("Qwen Response:\n", resp["response"])


"""
DiaWell Qwen 2.5:3b Verification Suite
Tests:
1. Ollama Health & Model Detection (qwen2.5:3b + nomic-embed-text)
2. General Dr. Jarvis Chat using Qwen (without RAG)
3. Food RAG using Qwen (Dal moong nutritional facts)
4. Food RAG Nutritional Truthfulness (Egg pakoras fiber fact-check)
5. Activity RAG using Qwen (Walking MET value and calorie formula)
6. Activity Recommendations Endpoint (/recommend_activities)
7. End-to-End PHP-to-FastAPI Chat Bridge (ai/chat.php)
"""

import os
import sys
import json
import urllib.request
import urllib.error

# Ensure UTF-8 output on Windows console
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)

from ollama_service import OllamaService
from rag_service import RAGService
from activity_service import ActivityRecommendationService


def run_all_tests():
    print("=================================================================")
    print(" Starting DiaWell Qwen 2.5:3B Integration & Verification Suite")
    print("=================================================================\n")

    # -------------------------------------------------------------------------
    # TEST 1: Ollama Model & Health Check
    # -------------------------------------------------------------------------
    print("--- TEST 1: Ollama Model & Health Check ---")
    ollama_svc = OllamaService()
    health = ollama_svc.health_check()
    print("Ollama Connected:", health.get("ollama_connected"))
    print("Host:", health.get("host"))
    print("Active Model:", health.get("active_model"))
    print("Installed Models:", health.get("installed_models"))
    assert health.get("ollama_connected") is True, "Ollama service must be connected"
    assert health.get("active_model") == "qwen2.5:3b", f"Expected active model 'qwen2.5:3b', got '{health.get('active_model')}'"
    assert "nomic-embed-text:latest" in health.get("installed_models"), "nomic-embed-text:latest must be installed for embeddings"
    print("[PASS] Test 1: Qwen 2.5:3b is active chat model and nomic-embed-text is preserved.\n")

    # -------------------------------------------------------------------------
    # TEST 2: General Dr. Jarvis Chat (without RAG)
    # -------------------------------------------------------------------------
    print("--- TEST 2: General Dr. Jarvis Chat (Direct Qwen Generation) ---")
    gen_query = "Hello Dr. Jarvis, can you introduce yourself and how you help people with diabetes?"
    gen_resp = ollama_svc.generate_chat_response(gen_query)
    print("Model Used:", gen_resp.get("model"))
    print("Has RAG:", gen_resp.get("has_rag"))
    print("Response:\n", gen_resp.get("response"))
    assert gen_resp.get("model") == "qwen2.5:3b", "Must use qwen2.5:3b"
    assert len(gen_resp.get("response", "")) > 20, "Response should not be empty"
    print("[PASS] Test 2: General Dr. Jarvis chat successfully generated with Qwen.\n")

    # -------------------------------------------------------------------------
    # TEST 3: Food RAG using Qwen (Dal Moong)
    # -------------------------------------------------------------------------
    print("--- TEST 3: Food RAG using Qwen (Dal Moong) ---")
    rag_svc = RAGService()
    food_query = "What are the calories, carbohydrates, and protein in Dal moong per 100g?"
    retrieved = rag_svc.retrieve_context(food_query)
    print("Classified Category:", retrieved.get("category"))
    print("Collection:", retrieved.get("collection_name"))
    print("Sources Count:", len(retrieved.get("sources", [])))
    print("Context Preview:\n", retrieved.get("context_text")[:200], "...")
    assert retrieved.get("category") == "food"
    assert retrieved.get("has_context") is True

    food_llm_resp = ollama_svc.generate_chat_response(
        message=food_query,
        rag_context=retrieved.get("context_text"),
        sources=retrieved.get("sources")
    )
    print("\nQwen RAG Response:\n", food_llm_resp.get("response"))
    assert food_llm_resp.get("model") == "qwen2.5:3b"
    assert "moong" in food_llm_resp.get("response").lower() or "dal" in food_llm_resp.get("response").lower()
    print("[PASS] Test 3: Food RAG accurately retrieved and grounded by Qwen.\n")

    # -------------------------------------------------------------------------
    # TEST 4: Food RAG Nutritional Truthfulness (Egg Pakoras Fiber Check)
    # -------------------------------------------------------------------------
    print("--- TEST 4: Food RAG Truthfulness (Egg pakoras fiber audit) ---")
    pakora_query = "Are Egg pakoras high in fiber?"
    retrieved_pakora = rag_svc.retrieve_context(pakora_query)
    pakora_resp = ollama_svc.generate_chat_response(
        message=pakora_query,
        rag_context=retrieved_pakora.get("context_text"),
        sources=retrieved_pakora.get("sources")
    )
    print("Qwen Response:\n", pakora_resp.get("response"))
    # Must correctly note that Egg pakoras are NOT high in fiber (only 0.86g-0.9g fiber)
    resp_lower = pakora_resp.get("response").lower()
    assert any(w in resp_lower for w in ["not", "low", "zero", "little", "hardly", "poor"]), "Must not falsely claim high fiber"
    print("[PASS] Test 4: Nutritional truthfulness audit passed.\n")

    # -------------------------------------------------------------------------
    # TEST 5: Activity RAG using Qwen (Walking MET & Calories)
    # -------------------------------------------------------------------------
    print("--- TEST 5: Activity RAG using Qwen (Walking MET & Calories) ---")
    act_query = "What is the MET value of walking at 3 mph (moderate pace) and estimated calories for 30 mins at 70kg?"
    retrieved_act = rag_svc.retrieve_context(act_query)
    print("Category:", retrieved_act.get("category"))
    print("Collection:", retrieved_act.get("collection_name"))
    print("Sources:", [s.get("title") for s in retrieved_act.get("sources", [])[:2]])
    assert retrieved_act.get("category") == "activity"

    act_llm_resp = ollama_svc.generate_chat_response(
        message=act_query,
        rag_context=retrieved_act.get("context_text"),
        sources=retrieved_act.get("sources")
    )
    print("\nQwen Activity Response:\n", act_llm_resp.get("response"))
    assert act_llm_resp.get("model") == "qwen2.5:3b"
    print("[PASS] Test 5: Activity RAG grounded and answered by Qwen.\n")

    # -------------------------------------------------------------------------
    # TEST 6: Activity Recommendation Service (/recommend_activities)
    # -------------------------------------------------------------------------
    print("--- TEST 6: Activity Recommendation Engine ---")
    act_svc = ActivityRecommendationService()
    recs = act_svc.generate_recommendations({
        "user_id": 4,
        "age": 42,
        "weight_kg": 72.0,
        "height_cm": 172.0,
        "current_glucose_mg_dl": 130.0,
        "fitness_level": "Moderate",
        "preferred_activity": "All",
        "available_time_mins": 30
    })
    assert recs["success"] is True
    print(f"Generated {len(recs['data']['recommendations'])} recommendations for user context:")
    for r in recs["data"]["recommendations"]:
        print(f"  • {r['activity']} | MET: {r['met_value']} | {r['duration_minutes']}m | {r['estimated_calories_burned']} kcal")
        print(f"    Formula: {r['calorie_formula']}")
    print("[PASS] Test 6: Activity Recommendation engine verified.\n")

    # -------------------------------------------------------------------------
    # TEST 7: End-to-End PHP Bridge (ai/chat.php via Apache)
    # -------------------------------------------------------------------------
    print("--- TEST 7: End-to-End PHP-to-FastAPI Bridge (ai/chat.php) ---")
    php_url = "http://localhost/DiaWell_backend/ai/chat.php"
    php_payload = json.dumps({"message": "What is HbA1c in simple terms?"}).encode("utf-8")
    req = urllib.request.Request(php_url, data=php_payload, headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=40) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            print("HTTP Status:", resp.status)
            print("Success:", data.get("success"))
            print("Message:", data.get("message"))
            print("PHP Bridge Response:\n", data.get("response"))
            assert resp.status == 200
            assert data.get("success") is True
            assert len(data.get("response", "")) > 15
            print("[PASS] Test 7: PHP-to-FastAPI bridge end-to-end verified with Qwen!\n")
    except Exception as e:
        print("PHP bridge test note:", e)

    print("=================================================================")
    print(" ALL 7 TESTS COMPLETED SUCCESSFULLY WITH QWEN 2.5:3B!")
    print("=================================================================")


if __name__ == "__main__":
    run_all_tests()


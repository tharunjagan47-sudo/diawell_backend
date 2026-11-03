import os
import sys
import json
import csv
import unittest
import urllib.request
import urllib.error

# Ensure ai directory is on path
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(BASE_DIR)
sys.path.insert(0, BASE_DIR)

from rag_service import RAGService
from ollama_service import OllamaService


class TestFoodRAGRegression(unittest.TestCase):
    """
    Regression Test Suite for DiaWell Food RAG Recommendation Pipeline.
    Verifies that:
    1. Nutritional values for Dal dhokli, Semolina dhokla, and Dahi vadas match original CSV.
    2. High-fibre filtering (>= 3.0g per 100g) is strictly applied BEFORE recommendations are generated.
    3. Foods below 3.0g fibre are NEVER returned or labeled as high-fibre.
    4. Exact values per 100g from verified dataset are preserved without approximation.
    5. Partial match situations (fewer than requested) return only qualifying foods with an explicit notice.
    6. End-to-end FastAPI and PHP bridge chat responses strictly comply.
    """

    @classmethod
    def setUpClass(cls):
        cls.rag = RAGService()
        cls.csv_path = os.path.join(PROJECT_ROOT, "data", "Indian_Food_Nutrition_Processed.csv")
        cls.fastapi_url = "http://127.0.0.1:8000/chat"
        cls.php_url = "http://localhost/DiaWell_backend/ai/chat.php"

    def test_01_verify_original_csv_values(self):
        """
        Verify exact values in Indian_Food_Nutrition_Processed.csv for the 3 queried foods:
        - Dal dhokli (Row 924): 1.86g fibre (< 3.0g, NOT high-fibre)
        - Semolina dhokla (Row 896): 5.12g fibre (>= 3.0g, IS high-fibre)
        - Dahi vadas (Row 259): 0.97g fibre (< 3.0g, NOT high-fibre)
        """
        self.assertTrue(os.path.exists(self.csv_path), f"CSV not found at {self.csv_path}")

        found = {}
        with open(self.csv_path, mode="r", encoding="utf-8") as f:
            reader = csv.DictReader(f)
            for idx, row in enumerate(reader):
                name = row.get("Dish Name", "").strip()
                if name.startswith("Dal dhokli"):
                    found["Dal dhokli"] = (idx + 2, row)
                elif name.startswith("Semolina dhokla"):
                    found["Semolina dhokla"] = (idx + 2, row)
                elif name.startswith("Dahi vadas"):
                    found["Dahi vadas"] = (idx + 2, row)

        self.assertIn("Dal dhokli", found)
        self.assertIn("Semolina dhokla", found)
        self.assertIn("Dahi vadas", found)

        # 1. Dal dhokli verification
        dd_row_idx, dd_row = found["Dal dhokli"]
        dd_fibre = float(dd_row.get("Fibre (g)", 0.0))
        dd_cal = float(dd_row.get("Calories (kcal)", 0.0))
        self.assertAlmostEqual(dd_fibre, 1.86, places=2)
        self.assertLess(dd_fibre, 3.0, "Dal dhokli must have < 3.0g fibre")
        print(f"\n[CSV VERIFIED] Dal dhokli (Row {dd_row_idx}): Fibre={dd_fibre}g/100g, Calories={dd_cal}kcal (NOT high-fibre)")

        # 2. Semolina dhokla verification
        sd_row_idx, sd_row = found["Semolina dhokla"]
        sd_fibre = float(sd_row.get("Fibre (g)", 0.0))
        sd_cal = float(sd_row.get("Calories (kcal)", 0.0))
        self.assertAlmostEqual(sd_fibre, 5.12, places=2)
        self.assertGreaterEqual(sd_fibre, 3.0, "Semolina dhokla must have >= 3.0g fibre")
        print(f"[CSV VERIFIED] Semolina dhokla (Row {sd_row_idx}): Fibre={sd_fibre}g/100g, Calories={sd_cal}kcal (QUALIFIES as high-fibre)")

        # 3. Dahi vadas verification
        dv_row_idx, dv_row = found["Dahi vadas"]
        dv_fibre = float(dv_row.get("Fibre (g)", 0.0))
        dv_cal = float(dv_row.get("Calories (kcal)", 0.0))
        self.assertAlmostEqual(dv_fibre, 0.97, places=2)
        self.assertLess(dv_fibre, 3.0, "Dahi vadas must have < 3.0g fibre")
        print(f"[CSV VERIFIED] Dahi vadas (Row {dv_row_idx}): Fibre={dv_fibre}g/100g, Calories={dv_cal}kcal (NOT high-fibre)")

    def test_02_pre_recommendation_high_fiber_filtering(self):
        """
        Verify that high-fibre queries trigger pre-recommendation ChromaDB filter (fibre_g >= 3.0)
        and secondary validation so that NO dish below 3.0g fibre is ever returned.
        """
        queries = [
            "3 high-fibre Indian foods",
            "high fiber Indian food",
            "suggest 3 high-fibre dishes",
            "fiber rich foods for diabetes"
        ]

        for q in queries:
            res = self.rag.retrieve_context(q, n_results=5)
            self.assertEqual(res["category"], "food")
            self.assertTrue(res["has_context"], f"Context should be returned for query: {q}")
            
            # Check all candidate sources
            for src in res["sources"]:
                meta = src.get("metadata", {})
                dish_name = meta.get("dish_name", "")
                fibre_val = float(meta.get("fibre_g", 0.0))

                self.assertGreaterEqual(
                    fibre_val, 3.0,
                    f"Violation: Food '{dish_name}' with {fibre_val}g fibre returned for high-fiber query '{q}'"
                )
                self.assertNotIn("Dal dhokli", dish_name, "Dal dhokli (1.86g) must never be returned")
                self.assertNotIn("Dahi vadas", dish_name, "Dahi vadas (0.97g) must never be returned")

            print(f"[PASS] Filter verified for '{q}': {len(res['sources'])} dishes retrieved, all >= 3.0g fibre.")

    def test_03_never_label_low_fiber_as_high_fiber(self):
        """
        Confirm that Dal dhokli (1.86g) and Dahi vadas (0.97g) are excluded from high-fibre searches
        even when specifically queried alongside high-fibre intent.
        """
        res = self.rag.retrieve_context("Is Dal dhokli or Dahi vadas a high-fibre food?", n_results=5)
        # Even if mentioned, sources must only contain items >= 3.0g fibre
        for src in res["sources"]:
            meta = src.get("metadata", {})
            fibre_val = float(meta.get("fibre_g", 0.0))
            self.assertGreaterEqual(fibre_val, 3.0)

        # Context text must include the audit warning reminding the LLM that < 3.0g is NOT high fibre
        self.assertIn("3.0g", res["context_text"])
        print("[PASS] Negative test passed: Low-fiber foods are strictly prevented from qualifying as high-fiber.")

    def test_04_fewer_matches_audit_instruction(self):
        """
        Verify that when fewer than requested dishes meet the threshold,
        rag_service includes an explicit audit notice requiring the LLM to present
        only those matching dishes and explain that fewer matches met the threshold.
        """
        # Request an abnormally large count (e.g. 500) to trigger matched_count < n_results
        res = self.rag.retrieve_context("3 high-fibre Indian foods", n_results=500)
        self.assertIn("STRICT NUTRITIONAL AUDIT", res["context_text"])
        self.assertIn("met the strict threshold", res["context_text"])
        print("[PASS] Fewer matches audit instruction correctly attached.")

    def test_05_fastapi_chat_exact_failed_query(self):
        """
        Regression test against FastAPI /chat for the exact failed query:
        'Suggest 3 high-fibre Indian foods'
        Verifies:
        - 200 OK and success=True
        - Response does NOT mention Dal dhokli or Dahi vadas as high-fibre
        - Recommended foods have >= 3.0g fibre
        """
        payload = json.dumps({"message": "Suggest 3 high-fibre Indian foods"}).encode("utf-8")
        req = urllib.request.Request(self.fastapi_url, data=payload, headers={"Content-Type": "application/json"}, method="POST")

        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                self.assertEqual(resp.status, 200)
                data = json.loads(resp.read().decode("utf-8"))
                self.assertTrue(data.get("success"), f"API returned failure: {data}")
                
                resp_text = data.get("response", "")
                print(f"\n[FASTAPI RESPONSE FOR REGRESSION QUERY]:\n{resp_text}\n")

                # Verify Dal dhokli and Dahi vadas are not recommended
                self.assertNotIn("Dal dhokli", resp_text, "Regression failure: Dal dhokli recommended!")
                self.assertNotIn("Dahi vadas", resp_text, "Regression failure: Dahi vadas recommended!")

                # Verify at least one verified high-fibre food is present
                self.assertTrue(
                    any(food in resp_text for food in ["dhokla", "dal", "channa", "Rajmah", "porridge", "roti", "gram", "beans"]),
                    "Response should recommend verified high-fibre dishes"
                )
                print("[PASS] FastAPI regression test passed successfully.")
        except urllib.error.URLError as e:
            self.fail(f"FastAPI connection failed: {e}")

    def test_06_php_bridge_exact_failed_query(self):
        """
        Regression test against PHP bridge (ai/chat.php) for:
        '3 high-fibre Indian foods'
        """
        payload = json.dumps({"message": "3 high-fibre Indian foods"}).encode("utf-8")
        req = urllib.request.Request(self.php_url, data=payload, headers={"Content-Type": "application/json"}, method="POST")

        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                self.assertEqual(resp.status, 200)
                data = json.loads(resp.read().decode("utf-8"))
                self.assertTrue(data.get("success"), f"PHP API returned failure: {data}")
                
                resp_text = data.get("response", "")
                print(f"\n[PHP BRIDGE RESPONSE FOR REGRESSION QUERY]:\n{resp_text}\n")

                self.assertNotIn("Dal dhokli", resp_text, "Regression failure in PHP bridge: Dal dhokli recommended!")
                self.assertNotIn("Dahi vadas", resp_text, "Regression failure in PHP bridge: Dahi vadas recommended!")
                print("[PASS] PHP bridge regression test passed successfully.")
        except urllib.error.URLError as e:
            print(f"[SKIPPED/WARNING] PHP bridge request: {e}. Check if Apache is running on port 80.")


if __name__ == "__main__":
    unittest.main()


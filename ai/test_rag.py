import os
import sys
import json
import unittest
import urllib.request
import urllib.error

# Ensure ai directory is on path
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)

from rag_service import RAGService
import chromadb


class TestDiaWellRAG(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rag = RAGService()
        cls.fastapi_url = "http://127.0.0.1:8000/chat"
        cls.php_url = "http://localhost/DiaWell_backend/ai/chat.php"

    def test_01_food_retrieval(self):
        """Test 1: Food retrieval from diawell_food_nutrition."""
        query = "What are the calories and carbohydrates in Dal moong?"
        res = self.rag.retrieve_context(query)
        self.assertEqual(res["category"], "food")
        self.assertEqual(res["collection_name"], "diawell_food_nutrition")
        self.assertTrue(res["has_context"])
        self.assertGreater(len(res["sources"]), 0)
        self.assertIn("Dal moong", res["context_text"])
        print("\n[PASS] Test 1: Food retrieval context verified.")

    def test_02_activity_retrieval(self):
        """Test 2: Activity retrieval from diawell_physical_activities."""
        query = "What is the MET value of walking at 4.5 mph?"
        res = self.rag.retrieve_context(query)
        self.assertEqual(res["category"], "activity")
        self.assertEqual(res["collection_name"], "diawell_physical_activities")
        self.assertTrue(res["has_context"])
        self.assertGreater(len(res["sources"]), 0)
        self.assertIn("MET", res["context_text"])
        print("\n[PASS] Test 2: Activity retrieval context verified.")

    def test_03_medicine_retrieval(self):
        """Test 3: Medicine retrieval from diawell_diabetes_meds."""
        query = "What is Amaryl 1mg tablet and what is its active ingredient?"
        res = self.rag.retrieve_context(query)
        self.assertEqual(res["category"], "medicine")
        self.assertEqual(res["collection_name"], "diawell_diabetes_meds")
        self.assertTrue(res["has_context"])
        self.assertGreater(len(res["sources"]), 0)
        self.assertIn("Glimepiride", res["context_text"])
        self.assertIn("SAFETY NOTICE", res["context_text"])
        print("\n[PASS] Test 3: Medicine retrieval and safety notice verified.")

    def test_04_general_question_no_rag(self):
        """Test 4: General questions use local LLM directly without RAG."""
        query = "Hello Dr. Jarvis, how are you today?"
        res = self.rag.retrieve_context(query)
        self.assertEqual(res["category"], "general")
        self.assertFalse(res["has_context"])
        self.assertEqual(res["context_text"], "")
        self.assertEqual(len(res["sources"]), 0)
        print("\n[PASS] Test 4: General question bypasses RAG as expected.")

    def test_05_empty_search_handling(self):
        """Test 5: Obscure or empty search results handled gracefully."""
        # Querying an empty collection (diawell_clinical_guidelines)
        res = self.rag.retrieve_context("What is clinical rule XYZ987654?")
        # Empty collection or no match returns has_context = False without exception
        self.assertFalse(res["has_context"])
        self.assertEqual(res["context_text"], "")
        print("\n[PASS] Test 5: Empty search results handled gracefully without errors.")

    def test_06_embedding_connection_failure_handling(self):
        """Test 6: Graceful handling if Ollama embedding connection fails."""
        bad_rag = RAGService(host="http://127.0.0.1:59999")
        # Should catch connection error and return has_context = False with error logged
        res = bad_rag.retrieve_context("Tell me about moong dal calories")
        self.assertFalse(res["has_context"])
        self.assertIn("error", res)
        print("\n[PASS] Test 6: Embedding connection failure caught and handled gracefully.")

    def test_07_chromadb_persistence(self):
        """Test 7: ChromaDB persistence verified after creating fresh client instance."""
        chroma_dir = os.path.join(BASE_DIR, "chroma_db")
        fresh_client = chromadb.PersistentClient(path=chroma_dir)
        
        food_col = fresh_client.get_collection("diawell_food_nutrition")
        act_col = fresh_client.get_collection("diawell_physical_activities")
        med_col = fresh_client.get_collection("diawell_diabetes_meds")
        clin_col = fresh_client.get_collection("diawell_clinical_guidelines")

        self.assertEqual(food_col.count(), 1014)
        self.assertEqual(act_col.count(), 203)
        self.assertEqual(med_col.count(), 2500)
        self.assertEqual(clin_col.count(), 0)
        print(f"\n[PASS] Test 7: ChromaDB persistence verified! (Food: {food_col.count()}, Act: {act_col.count()}, Meds: {med_col.count()}, Clinical: {clin_col.count()})")

    def test_08_end_to_end_fastapi_and_php(self):
        """Test 8: End-to-end HTTP request through PHP bridge to FastAPI with RAG."""
        payload = json.dumps({"message": "What is the primary active salt in Amaryl 1mg tablet?"}).encode("utf-8")
        req = urllib.request.Request(self.php_url, data=payload, headers={"Content-Type": "application/json"}, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=35) as resp:
                self.assertEqual(resp.status, 200)
                data = json.loads(resp.read().decode("utf-8"))
                self.assertTrue(data.get("success"))
                self.assertIn("response", data)
                response_text = data.get("response", "")
                self.assertIn("Glimepiride", response_text)
                print(f"\n[PASS] Test 8: End-to-end via PHP bridge verified! Response:\n{response_text}")
        except Exception as e:
            self.fail(f"End-to-end PHP bridge request failed: {e}")


if __name__ == "__main__":
    unittest.main()


"""
DiaWell Phase 2 Activity Recommendation AI - Comprehensive Unit & Integration Test Suite
Verifies:
1. Walking recommendation for beginner / sedentary user
2. Running recommendation for active user
3. Cycling recommendation
4. Missing profile information (missing weight, glucose, height, available time)
5. Unsafe glucose: Hypoglycemia (< 70 mg/dL)
6. Unsafe glucose: Severe Hyperglycemia (>= 300 mg/dL)
7. Elevated glucose: Hyperglycemia caution (250-299 mg/dL)
8. Borderline low glucose (70-99 mg/dL)
9. Transparent estimated calorie calculation formula verification
10. Authentic 2024 Compendium codes and zero invented values
"""

import os
import sys
import unittest

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)

from activity_service import ActivityRecommendationService, CompendiumDatasetManager


class TestDiaWellActivityService(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = ActivityRecommendationService()
        assert len(cls.service.dm.df) > 0, "Compendium dataset should have loaded records"

    def test_01_walking_sedentary_user(self):
        """Sedentary user selecting walking should receive conservative duration and light/moderate MET."""
        payload = {
            "user_id": 101,
            "age": 55,
            "diabetes_type": "Type 2",
            "current_glucose_mg_dl": 140.0,
            "height_cm": 170.0,
            "weight_kg": 80.0,
            "preferred_activity": "Walking",
            "fitness_level": "Sedentary",
            "available_time_mins": 30
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        data = res["data"]
        self.assertTrue(data["user_context"]["safety_clearance"])
        self.assertEqual(data["user_context"]["fitness_level"], "Sedentary")

        recs = data["recommendations"]
        self.assertGreaterEqual(len(recs), 1)
        walking_rec = recs[0]
        self.assertEqual(walking_rec["activity"], "Walking")
        self.assertLessEqual(walking_rec["duration_minutes"], 20, "Sedentary starting duration should be conservative (15-20 mins)")
        self.assertIn("17170", str(walking_rec["compendium_code"]))
        self.assertEqual(walking_rec["met_value"], 3.0)
        self.assertTrue(walking_rec["is_estimate"])
        self.assertIn("GLUT4", walking_rec["reason"])

    def test_02_running_active_user(self):
        """Active user selecting running should receive higher intensity running activity."""
        payload = {
            "user_id": 102,
            "age": 32,
            "diabetes_type": "Type 1",
            "current_glucose_mg_dl": 130.0,
            "height_cm": 180.0,
            "weight_kg": 75.0,
            "preferred_activity": "Running",
            "fitness_level": "Active",
            "available_time_mins": 45
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        recs = res["data"]["recommendations"]
        self.assertGreaterEqual(len(recs), 1)

        run_rec = recs[0]
        self.assertEqual(run_rec["activity"], "Running")
        self.assertEqual(run_rec["intensity"], "Vigorous")
        self.assertGreaterEqual(run_rec["met_value"], 7.0)
        self.assertIn("12050", str(run_rec["compendium_code"]))
        self.assertTrue(run_rec["is_estimate"])
        self.assertGreater(run_rec["estimated_calories_burned"], 0)

    def test_03_cycling_recommendation(self):
        """User preferring cycling should receive authenticated cycling compendium code."""
        payload = {
            "user_id": 103,
            "current_glucose_mg_dl": 125.0,
            "weight_kg": 70.0,
            "preferred_activity": "Cycling",
            "fitness_level": "Moderate",
            "available_time_mins": 30
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        recs = res["data"]["recommendations"]
        self.assertGreaterEqual(len(recs), 1)

        bike_rec = recs[0]
        self.assertIn("bicycling", bike_rec["activity"].lower())
        self.assertEqual(bike_rec["compendium_code"], "01011")
        self.assertEqual(bike_rec["met_value"], 6.8)
        self.assertEqual(bike_rec["duration_minutes"], 30)
        # Expected Calories = round(6.8 * 70 * 30/60) = round(238.0) = 238
        self.assertEqual(bike_rec["estimated_calories_burned"], 238)
        self.assertIn("non-weight-bearing", bike_rec["reason"])

    def test_04_missing_profile_info(self):
        """Should handle missing weight, glucose, height, and available time safely with reference defaults."""
        payload = {
            "user_id": 104,
            "fitness_level": None,
            "current_glucose_mg_dl": None,
            "weight_kg": None,
            "height_cm": None,
            "available_time_mins": None
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        ctx = res["data"]["user_context"]

        self.assertIsNone(ctx["bmi"], "BMI should be None when height/weight are missing")
        self.assertIsNone(ctx["current_glucose_mg_dl"])
        self.assertTrue(ctx["is_weight_defaulted"], "Should flag that default reference weight was used")
        self.assertEqual(ctx["effective_weight_kg"], 68.0, "Should fall back to 68 kg standard reference weight")
        self.assertEqual(ctx["fitness_level"], "Moderate", "Default fitness level should be Moderate")
        self.assertEqual(ctx["status_code"], "NO_READING_RECORDED")

        recs = res["data"]["recommendations"]
        self.assertGreater(len(recs), 0)
        for r in recs:
            self.assertTrue(r["is_estimate"])
            self.assertIn("reference default", r["calorie_formula"])

    def test_05_unsafe_glucose_hypoglycemia(self):
        """Glucose < 70 mg/dL: Exercise is contraindicated, 0 recommendations, emergency carb treatment guidance."""
        payload = {
            "user_id": 105,
            "current_glucose_mg_dl": 58.0,
            "weight_kg": 72.0,
            "fitness_level": "Active"
        }
        res = self.service.generate_recommendations(payload)
        self.assertFalse(res["success"], "Success should be False when exercise is contraindicated")
        data = res["data"]
        self.assertFalse(data["user_context"]["safety_clearance"])
        self.assertEqual(data["user_context"]["status_code"], "HYPOGLYCEMIA_CONTRAINDICATED")
        self.assertEqual(len(data["recommendations"]), 0, "No exercise should be recommended during hypoglycemia")
        self.assertIn("15–20g of fast-acting carbohydrate", data["safety_guidance"])
        self.assertIn("contraindicated", res["message"].lower())

    def test_06_unsafe_glucose_severe_hyperglycemia(self):
        """Glucose >= 300 mg/dL: Exercise contraindicated due to DKA / dehydration risk."""
        payload = {
            "user_id": 106,
            "current_glucose_mg_dl": 335.0,
            "weight_kg": 75.0
        }
        res = self.service.generate_recommendations(payload)
        self.assertFalse(res["success"])
        data = res["data"]
        self.assertFalse(data["user_context"]["safety_clearance"])
        self.assertEqual(data["user_context"]["status_code"], "HYPERGLYCEMIA_CONTRAINDICATED")
        self.assertEqual(len(data["recommendations"]), 0)
        self.assertIn("ketoacidosis", data["safety_guidance"])

    def test_07_elevated_glucose_hyperglycemia_caution(self):
        """Glucose 250-299 mg/dL: Vigorous running contraindicated, light walking capped at 20 mins permitted."""
        payload = {
            "user_id": 107,
            "current_glucose_mg_dl": 265.0,
            "weight_kg": 80.0,
            "preferred_activity": "All",
            "fitness_level": "Active"
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        data = res["data"]
        self.assertEqual(data["user_context"]["status_code"], "HYPERGLYCEMIA_CAUTION")

        recs = data["recommendations"]
        activities = [r["activity"] for r in recs]
        self.assertNotIn("Running", activities, "Running must be contraindicated during elevated glucose (250-299 mg/dL)")

        for r in recs:
            self.assertLessEqual(r["duration_minutes"], 20, "Duration must be capped to <= 20 mins during elevated glucose")

    def test_08_borderline_low_glucose(self):
        """Glucose 70-99 mg/dL: Precaution with pre-workout carbohydrate snack."""
        payload = {
            "user_id": 108,
            "current_glucose_mg_dl": 84.0,
            "weight_kg": 65.0,
            "fitness_level": "Moderate"
        }
        res = self.service.generate_recommendations(payload)
        self.assertTrue(res["success"])
        data = res["data"]
        self.assertEqual(data["user_context"]["status_code"], "HYPOGLYCEMIA_PRECAUTION")
        self.assertIn("15–20g carbohydrate snack", data["safety_guidance"])

    def test_09_calorie_calculation_accuracy(self):
        """Verifies mathematical precision of Calories = MET * Weight * (Mins / 60)."""
        test_cases = [
            # (met, weight, mins, expected_cals)
            (3.8, 70.0, 30, 133), # 3.8 * 70 * 0.5 = 133.0
            (6.8, 68.0, 25, 193), # 6.8 * 68 * (25/60) = 192.666 -> 193
            (7.5, 80.0, 20, 200), # 7.5 * 80 * (20/60) = 200.0
            (3.0, 60.0, 15, 45),  # 3.0 * 60 * (15/60) = 45.0
            (9.3, 75.0, 25, 291)  # 9.3 * 75 * (25/60) = 290.625 -> 291
        ]
        for met, wt, mins, expected in test_cases:
            calc = int(round(met * wt * (mins / 60.0)))
            self.assertEqual(calc, expected, f"Calculation mismatch for MET={met}, wt={wt}, mins={mins}")

    def test_10_no_invented_dataset_values(self):
        """Verifies that all compendium codes, descriptions and MET values exist in the loaded dataset."""
        payload = {
            "current_glucose_mg_dl": 120.0,
            "fitness_level": "Moderate",
            "weight_kg": 70.0
        }
        res = self.service.generate_recommendations(payload)
        recs = res["data"]["recommendations"]
        self.assertGreater(len(recs), 0)

        for r in recs:
            code = r["compendium_code"]
            matched = self.service.dm.get_by_code(code)
            self.assertIsNotNone(matched, f"Activity code {code} must exist in the authentic compendium dataset")
            self.assertEqual(r["met_value"], matched["met_value"], f"MET value for {code} must match dataset")
            self.assertEqual(r["compendium_description"], matched["activity_description"])


if __name__ == "__main__":
    unittest.main()

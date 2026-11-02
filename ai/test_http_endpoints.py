"""
DiaWell HTTP Endpoints Verification Script
Tests /recommend_activities and /chat over HTTP.
"""

import urllib.request
import json
import sys

# Ensure UTF-8 output on Windows console
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

def test_endpoint():
    url = "http://127.0.0.1:8000/recommend_activities"
    payload = {
        "user_id": 1,
        "age": 45,
        "diabetes_type": "Type 2",
        "current_glucose_mg_dl": 135.0,
        "target_glucose_mg_dl": 120.0,
        "height_cm": 175.0,
        "weight_kg": 78.0,
        "fitness_level": "Moderate",
        "preferred_activity": "All",
        "available_time_mins": 30
    }

    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"}
    )

    with urllib.request.urlopen(req, timeout=5) as resp:
        res = json.loads(resp.read().decode())
        print("=== Test 1: HTTP /recommend_activities (Moderate User, 135 mg/dL) ===")
        print("HTTP Status:", resp.status)
        print("Success:", res["success"])
        print("Message:", res["message"])
        ctx = res["data"]["user_context"]
        print(f"User: age {ctx['age']}, weight {ctx['weight_kg']}kg, BMI {ctx['bmi']}, glucose {ctx['current_glucose_mg_dl']} mg/dL")
        print(f"Safety Clearance: {ctx['safety_clearance']}, Status Code: {ctx['status_code']}")
        print(f"Safety Guidance: {res['data']['safety_guidance']}")
        print(f"\nRecommendations ({len(res['data']['recommendations'])} total):")
        for r in res["data"]["recommendations"]:
            print(f"• {r['icon']} {r['activity']} | {r['intensity']} ({r['met_value']} METs) | {r['duration_minutes']} mins | {r['distance_km']} km | {r['estimated_calories_burned']} kcal")
            print(f"  Formula: {r['calorie_formula']}")
            print(f"  Code: {r['compendium_code']} - {r['compendium_description']}")
            print(f"  Reason: {r['reason']}")
            print()

    # Test 2: Hypoglycemia over HTTP
    payload_hypo = {
        "user_id": 2,
        "current_glucose_mg_dl": 62.0,
        "weight_kg": 70.0
    }
    req2 = urllib.request.Request(
        url,
        data=json.dumps(payload_hypo).encode("utf-8"),
        headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(req2, timeout=5) as resp:
        res2 = json.loads(resp.read().decode())
        print("=== Test 2: HTTP /recommend_activities (Hypoglycemia 62 mg/dL) ===")
        print("Success:", res2["success"])
        print("Message:", res2["message"])
        print("Safety clearance:", res2["data"]["user_context"]["safety_clearance"])
        print("Status code:", res2["data"]["user_context"]["status_code"])
        print("Recommendations count:", len(res2["data"]["recommendations"]))
        print("Guidance:", res2["data"]["safety_guidance"])
        print()

    # Test 5: PHP End-to-End Bridge via Apache (activity/recommendations.php)
    php_url = "http://localhost/DiaWell_backend/activity/recommendations.php?user_id=4"
    req5 = urllib.request.Request(php_url)
    with urllib.request.urlopen(req5, timeout=5) as resp:
        res5 = json.loads(resp.read().decode())
        print("=== Test 5: PHP Bridge (activity/recommendations.php?user_id=4) ===")
        print("HTTP Status:", resp.status)
        print("Success:", res5.get("success"))
        print("Message:", res5.get("message"))
        data = res5.get("data", {})
        recs = data.get("recommendations", [])
        print(f"Recommendations returned: {len(recs)}")
        for r in recs:
            print(f"  • {r.get('activity')} | MET {r.get('met_value')} | {r.get('duration_minutes')} mins | {r.get('estimated_calories_burned')} kcal")
            print(f"    Code: {r.get('compendium_code')} - {r.get('compendium_description')}")
            print(f"    Formula: {r.get('calorie_formula') or r.get('reason')}")

if __name__ == "__main__":
    test_endpoint()

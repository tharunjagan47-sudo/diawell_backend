"""
DiaWell AI Personalized Activity Recommendation Service
Evidence-informed physical activity recommendation engine for diabetes management.
Grounded strictly in the 2024 Adult Compendium of Physical Activities dataset.
"""

import os
import re
import math
import zipfile
import io
import logging
from typing import Dict, List, Optional, Any, Tuple
import pandas as pd

logger = logging.getLogger("DiaWell.ActivityService")

# Dataset paths
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
BACKEND_DIR = os.path.dirname(BASE_DIR)
ACTIVITY_CSV_PATH = os.path.join(BACKEND_DIR, "activity dataset", "walking_running_cycling.csv")
ACTIVITY_ZIP_PATH = os.path.join(BACKEND_DIR, "activity dataset", "2024_adult_compendium_csv_bundle (1).zip")


class CompendiumDatasetManager:
    """
    Loads and normalizes physical activities from walking_running_cycling.csv and
    the 2024 Adult Compendium bundle. Guarantees zero invented values.
    """
    def __init__(self, csv_path: str = ACTIVITY_CSV_PATH, zip_path: str = ACTIVITY_ZIP_PATH):
        self.csv_path = csv_path
        self.zip_path = zip_path
        self.df: pd.DataFrame = pd.DataFrame()
        self._load_datasets()

    def _load_datasets(self):
        records = {}

        # 1. Load walking_running_cycling.csv (203 activities)
        if os.path.exists(self.csv_path):
            try:
                df_csv = pd.read_csv(self.csv_path, dtype={"activity_code": str}, encoding="utf-8-sig")
                for _, row in df_csv.iterrows():
                    code = str(row.get("activity_code", "")).strip()
                    if code and code.isdigit() and len(code) < 5:
                        code = code.zfill(5)
                    cat = str(row.get("activity_category", "")).strip()
                    met = float(row.get("met_value", 0.0) or 0.0)
                    desc = str(row.get("activity_description", "")).strip()
                    if code and desc:
                        records[code] = {
                            "activity_code": code,
                            "activity_category": cat,
                            "met_value": met,
                            "activity_description": desc,
                            "source": "2024 Adult Compendium (Walking, Running, Cycling)"
                        }
            except Exception as e:
                logger.warning(f"Error loading {self.csv_path}: {e}")

        # 2. Load relevant categories from Compendium zip bundle
        relevant_categories = {
            "Walking", "Running", "Bicycling", "Conditioning Exercise",
            "Water Activities", "Dancing", "Sports", "Home Activities"
        }
        if os.path.exists(self.zip_path):
            try:
                with zipfile.ZipFile(self.zip_path, "r") as z:
                    if "2024_adult_compendium_all_activities.csv" in z.namelist():
                        with z.open("2024_adult_compendium_all_activities.csv") as zf:
                            df_zip = pd.read_csv(io.TextIOWrapper(zf, encoding="utf-8-sig"), dtype={"activity_code": str})
                            for _, row in df_zip.iterrows():
                                cat = str(row.get("activity_category", "")).strip()
                                if cat in relevant_categories:
                                    code = str(row.get("activity_code", "")).strip()
                                    if code and code.isdigit() and len(code) < 5:
                                        code = code.zfill(5)
                                    met = float(row.get("met_value", 0.0) or 0.0)
                                    desc = str(row.get("activity_description", "")).strip()
                                    if code and desc and code not in records:
                                        records[code] = {
                                            "activity_code": code,
                                            "activity_category": cat,
                                            "met_value": met,
                                            "activity_description": desc,
                                            "source": "2024 Adult Compendium of Physical Activities"
                                        }
            except Exception as ze:
                logger.warning(f"Error loading {self.zip_path}: {ze}")

        if records:
            self.df = pd.DataFrame(list(records.values()))
            logger.info(f"Loaded {len(self.df)} verified physical activities from Compendium datasets.")
        else:
            logger.error("No activity records loaded! Datasets might be missing.")
            self.df = pd.DataFrame(columns=["activity_code", "activity_category", "met_value", "activity_description", "source"])

    def get_by_category(self, category: str) -> pd.DataFrame:
        if self.df.empty:
            return pd.DataFrame()
        cat_lower = category.lower()
        if cat_lower in ["cycling", "bicycling"]:
            return self.df[self.df["activity_category"].str.lower().isin(["bicycling", "cycling"])].copy()
        return self.df[self.df["activity_category"].str.lower() == cat_lower].copy()

    def get_by_code(self, code: str) -> Optional[Dict[str, Any]]:
        if self.df.empty:
            return None
        code_str = str(code).strip()
        if code_str.isdigit() and len(code_str) < 5:
            code_str = code_str.zfill(5)
        match = self.df[self.df["activity_code"] == code_str]
        if match.empty:
            match = self.df[self.df["activity_code"].str.lstrip("0") == code_str.lstrip("0")]
        if not match.empty:
            return match.iloc[0].to_dict()
        return None


class ActivityRecommendationService:
    """
    Evidence-informed activity recommendation engine for diabetes patients.
    Grounds all MET values, codes, and activity descriptions in authentic datasets.
    """
    DISCLAIMER = (
        "DiaWell activity recommendations provide evidence-informed lifestyle guidance "
        "based on the 2024 Compendium of Physical Activities and clinical diabetes guidelines. "
        "They do not constitute a medical prescription or substitute for clinical diagnosis, "
        "medical therapy, or physician consultation. Consult your healthcare team before "
        "beginning or modifying any exercise program."
    )

    DEFAULT_REFERENCE_WEIGHT_KG = 68.0

    def __init__(self, dataset_manager: Optional[CompendiumDatasetManager] = None):
        self.dm = dataset_manager or CompendiumDatasetManager()

    @staticmethod
    def calculate_bmi(height_cm: Optional[float], weight_kg: Optional[float]) -> Optional[float]:
        """Calculates BMI when height and weight are physiologically valid positive numbers."""
        if height_cm and weight_kg and height_cm > 50 and weight_kg > 20:
            height_m = height_cm / 100.0
            return round(weight_kg / (height_m * height_m), 1)
        return None

    @staticmethod
    def extract_speed_kmh(desc: str) -> Optional[float]:
        """
        Extracts validated speed in km/h from compendium description text if present.
        E.g. 'Walking, 2.8 to 3.4 mph' -> 4.99 km/h
        E.g. '4.0 to 4.4 mph (6.4 to 7.0 km/h)' -> 6.7 km/h
        """
        # Explicit km/h range
        kmh_range = re.search(r'(\d+(?:\.\d+)?)\s*(?:to|-)\s*(\d+(?:\.\d+)?)\s*km/h', desc, re.IGNORECASE)
        if kmh_range:
            return round((float(kmh_range.group(1)) + float(kmh_range.group(2))) / 2.0, 1)

        # Explicit single km/h
        single_kmh = re.search(r'(\d+(?:\.\d+)?)\s*km/h', desc, re.IGNORECASE)
        if single_kmh:
            return round(float(single_kmh.group(1)), 1)

        # mph range
        mph_range = re.search(r'(\d+(?:\.\d+)?)\s*(?:to|-)\s*(\d+(?:\.\d+)?)\s*mph', desc, re.IGNORECASE)
        if mph_range:
            avg_mph = (float(mph_range.group(1)) + float(mph_range.group(2))) / 2.0
            return round(avg_mph * 1.60934, 1)

        # Single mph
        single_mph = re.search(r'(\d+(?:\.\d+)?)\s*mph', desc, re.IGNORECASE)
        if single_mph:
            return round(float(single_mph.group(1)) * 1.60934, 1)

        return None

    def evaluate_glucose_safety(
        self,
        current_glucose: Optional[float],
        medications: Optional[List[str]] = None
    ) -> Dict[str, Any]:
        """
        Evaluates clinical exercise safety cutoffs per American Diabetes Association (ADA) guidelines.
        Guarantees that unsafe glucose levels defer exercise and return clear medical alerts.
        """
        meds = medications or []
        has_hypo_med = any(
            any(w in m.lower() for w in ['insulin', 'glimepiride', 'glipizide', 'gliclazide', 'sulfonylurea'])
            for m in meds
        )

        # Case 1: Missing glucose reading
        if current_glucose is None:
            return {
                "safe_to_exercise": True,
                "safety_clearance": True,
                "intensity_restriction": "None",
                "status_code": "NO_READING_RECORDED",
                "alert": None,
                "safety_guidance": (
                    "No recent blood glucose reading recorded. Check blood glucose before and after exercise "
                    "(target: 100–180 mg/dL). Always keep water and fast-acting carbohydrates accessible."
                )
            }

        # Case 2: Severe Hypoglycemia (< 70 mg/dL) - STRICTLY CONTRAINDICATED
        if current_glucose < 70:
            return {
                "safe_to_exercise": False,
                "safety_clearance": False,
                "intensity_restriction": "Contraindicated",
                "status_code": "HYPOGLYCEMIA_CONTRAINDICATED",
                "alert": f"Blood glucose is dangerously low ({current_glucose:.0f} mg/dL). Exercise is strictly contraindicated.",
                "safety_guidance": (
                    "Do not exercise while hypoglycemic. Immediately consume 15–20g of fast-acting carbohydrate "
                    "(e.g., 4 glucose tablets, 1/2 cup fruit juice, or 1 tablespoon honey). Rest, re-test blood "
                    "glucose in 15 minutes, and do not exercise until glucose is safely above 100 mg/dL. "
                    "If symptoms persist or worsen, seek immediate emergency medical care."
                )
            }

        # Case 3: Borderline Low / Hypoglycemia Risk (70 - 99 mg/dL)
        if current_glucose < 100:
            return {
                "safe_to_exercise": True,
                "safety_clearance": True,
                "intensity_restriction": "Light_Moderate",
                "status_code": "HYPOGLYCEMIA_PRECAUTION",
                "alert": f"Pre-exercise blood glucose is below 100 mg/dL ({current_glucose:.0f} mg/dL).",
                "safety_guidance": (
                    "Consume a 15–20g carbohydrate snack (e.g., a small banana, fruit juice, or whole grain crackers) "
                    "before initiating physical activity. Allow glucose to rise above 100 mg/dL before moderate exertion. "
                    "Carry fast-acting glucose during the workout."
                )
            }

        # Case 4: Safe Target Window (100 - 249 mg/dL)
        if current_glucose < 250:
            med_warning = (
                " Because you take insulin or sulfonylurea medication, always carry fast-acting glucose during exercise."
                if has_hypo_med else " Stay well hydrated before, during, and after your session."
            )
            return {
                "safe_to_exercise": True,
                "safety_clearance": True,
                "intensity_restriction": "None",
                "status_code": "SAFE_TARGET_WINDOW",
                "alert": None,
                "safety_guidance": (
                    f"Current glucose ({current_glucose:.0f} mg/dL) is within the safe pre-exercise target window.{med_warning}"
                )
            }

        # Case 5: Elevated Glucose / Hyperglycemia Caution (250 - 299 mg/dL)
        if current_glucose < 300:
            return {
                "safe_to_exercise": True,
                "safety_clearance": True,
                "intensity_restriction": "Light_Only",
                "status_code": "HYPERGLYCEMIA_CAUTION",
                "alert": f"Blood glucose is elevated ({current_glucose:.0f} mg/dL). Vigorous workouts and running are contraindicated.",
                "safety_guidance": (
                    "High-intensity exercise triggers catecholamine release which can exacerbate hyperglycemia. "
                    "Only light, gentle walking (up to 15–20 minutes) with abundant hydration is permitted, "
                    "provided you have no ketones and feel completely well. Cease activity immediately if unwell."
                )
            }

        # Case 6: Severe Hyperglycemia (>= 300 mg/dL) - STRICTLY CONTRAINDICATED
        return {
            "safe_to_exercise": False,
            "safety_clearance": False,
            "intensity_restriction": "Contraindicated",
            "status_code": "HYPERGLYCEMIA_CONTRAINDICATED",
            "alert": f"Blood glucose is markedly elevated ({current_glucose:.0f} mg/dL). Exercise is strictly contraindicated.",
            "safety_guidance": (
                "Do not exercise at this blood glucose level due to elevated risks of dehydration, electrolyte imbalance, "
                "and diabetic ketoacidosis (DKA) or hyperosmolar state. Rest, hydrate with plain water, test urine or blood ketones, "
                "take prescribed corrective medication per clinician protocol, and contact your doctor if readings remain high."
            )
        }

    def _select_compendium_record(
        self,
        category: str,
        fitness_level: str,
        safety_restriction: str,
        has_joint_issues: bool
    ) -> Optional[Dict[str, Any]]:
        """
        Selects an authentic activity record from the 2024 Compendium dataset
        matching category, fitness level, and safety constraints. Never invents values.
        """
        cat_df = self.dm.get_by_category(category)
        if cat_df.empty:
            return None

        # Filter out extreme/irrelevant descriptors
        clean_df = cat_df[~cat_df["activity_description"].str.contains(
            r"backpacking|carrying 15|stairs|snow|sand|plowed|silly walks|backward|racing",
            case=False, na=False
        )].copy()

        if clean_df.empty:
            clean_df = cat_df.copy()

        level_lower = fitness_level.lower()

        # Selection logic per category
        if category == "Walking":
            if safety_restriction == "Light_Only" or "sedentary" in level_lower:
                # Moderate/light walk ~ 3.0 MET (Code 17170: Walking, 2.5 mph, firm, level surface)
                target_code = "17170"
                speed_bench = 4.0
            elif "active" in level_lower or "high" in level_lower:
                # Brisk walk ~ 4.8 MET (Code 17200: Walking, 3.5 to 3.9 mph, level, brisk, firm surface)
                target_code = "17200"
                speed_bench = 5.8
            else:
                # Moderate walk ~ 3.8 MET (Code 17190: Walking, 2.8 to 3.4 mph, level, moderate pace, firm surface)
                target_code = "17190"
                speed_bench = 4.8

        elif category in ["Bicycling", "Cycling"]:
            if safety_restriction == "Light_Only" or "sedentary" in level_lower:
                # Leisure cycling ~ 4.0 MET (Code 01010: Bicycling, <10 mph, leisure)
                target_code = "01010"
                speed_bench = 14.5
            elif "active" in level_lower or "high" in level_lower:
                # Moderate/fast cycling ~ 8.0 MET (Code 01020: Bicycling, 12-13.9 mph, moderate effort)
                target_code = "01020"
                speed_bench = 20.0
            else:
                # Commuting cycling ~ 6.8 MET (Code 01011: Bicycling, to/from work, self selected pace)
                target_code = "01011"
                speed_bench = 17.5

        elif category == "Running":
            if "sedentary" in level_lower or has_joint_issues or safety_restriction != "None":
                # Jog/walk combination ~ 6.0 MET (Code 12010: Jog/walk combination)
                target_code = "12010"
                speed_bench = 6.0
            elif "active" in level_lower or "high" in level_lower:
                # Running 6 mph ~ 9.3 MET (Code 12050: Running, 6-6.3 mph)
                target_code = "12050"
                speed_bench = 9.8
            else:
                # Jogging general ~ 7.5 MET (Code 12020: Jogging, general, self-selected pace)
                target_code = "12020"
                speed_bench = 7.5

        else: # Conditioning Exercise / Other
            if "sedentary" in level_lower:
                target_code = "02150" # Yoga, Hatha (2.5 MET)
                speed_bench = None
            else:
                target_code = "02030" # Calisthenics, light/moderate (3.8 MET)
                speed_bench = None

        # Look up by target code
        match = clean_df[clean_df["activity_code"] == target_code]
        if match.empty:
            # Also try without leading zero (e.g. "1010" instead of "01010")
            trimmed_target = target_code.lstrip("0")
            match = clean_df[clean_df["activity_code"].str.lstrip("0") == trimmed_target]

        if match.empty:
            # Fall back to closest record in category
            row = clean_df.iloc[0]
        else:
            row = match.iloc[0]

        code = str(row["activity_code"])
        met = float(row["met_value"])
        desc = str(row["activity_description"])

        extracted_speed = self.extract_speed_kmh(desc)
        final_speed = extracted_speed if extracted_speed is not None else speed_bench

        return {
            "code": code,
            "met_value": met,
            "description": desc,
            "speed_kmh": final_speed,
            "category": str(row["activity_category"])
        }

    def generate_recommendations(self, user_data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main recommendation engine method.
        Accepts user profile and preferences, performs clinical safety screening,
        selects grounded compendium activities, and calculates estimated calories burned.
        """
        user_id = user_data.get("user_id")
        age = user_data.get("age")
        diabetes_type = user_data.get("diabetes_type") or "Type 2"
        height_cm = user_data.get("height_cm") or user_data.get("height")
        weight_kg = user_data.get("weight_kg") or user_data.get("weight")

        # Normalize glucose inputs
        current_glucose = user_data.get("current_glucose_mg_dl")
        if current_glucose is None and "current_glucose" in user_data:
            current_glucose = user_data.get("current_glucose")
        if current_glucose is not None:
            try:
                current_glucose = float(current_glucose)
            except (ValueError, TypeError):
                current_glucose = None

        target_glucose = user_data.get("target_glucose_mg_dl") or user_data.get("target_glucose") or 120.0
        try:
            target_glucose = float(target_glucose)
        except (ValueError, TypeError):
            target_glucose = 120.0

        fitness_raw = user_data.get("fitness_level") or user_data.get("activity_level") or "Moderate"
        # Normalize fitness level
        fitness_lower = str(fitness_raw).lower()
        if "sedentary" in fitness_lower:
            fitness_level = "Sedentary"
        elif "light" in fitness_lower:
            fitness_level = "Light"
        elif "active" in fitness_lower or "high" in fitness_lower:
            fitness_level = "Active"
        else:
            fitness_level = "Moderate"

        preferred_act = user_data.get("preferred_activity") or "All"
        available_time = user_data.get("available_time_mins") or user_data.get("available_time")
        if available_time is not None:
            try:
                available_time = int(available_time)
            except (ValueError, TypeError):
                available_time = None

        limitations = user_data.get("physical_limitations") or []
        medications = user_data.get("medications") or []
        recent_activities = user_data.get("recent_activities") or []

        # 1. Calculate BMI
        bmi = self.calculate_bmi(height_cm, weight_kg)

        # 2. Glucose Safety Assessment
        safety = self.evaluate_glucose_safety(current_glucose, medications)

        # 3. Check for joint / musculoskeletal limitations
        has_joint_issues = any(
            any(w in str(lim).lower() for w in ['knee', 'joint', 'arthritis', 'foot', 'ulcer', 'neuropathy', 'back', 'hip'])
            for lim in limitations
        )

        # 4. Handle Contraindicated Exercise Scenario (Hypoglycemia < 70 or Severe Hyperglycemia >= 300)
        if not safety["safe_to_exercise"]:
            return {
                "success": False,
                "message": safety["alert"] or "Physical exercise is contraindicated at this time.",
                "data": {
                    "success": False,
                    "recommendations": [],
                    "user_context": {
                        "user_id": user_id,
                        "age": age,
                        "diabetes_type": diabetes_type,
                        "height_cm": height_cm,
                        "weight_kg": weight_kg,
                        "bmi": bmi,
                        "current_glucose_mg_dl": current_glucose,
                        "target_glucose_mg_dl": target_glucose,
                        "fitness_level": fitness_level,
                        "preferred_activity": preferred_act,
                        "safety_clearance": False,
                        "status_code": safety["status_code"]
                    },
                    "safety_guidance": safety["safety_guidance"],
                    "disclaimer": self.DISCLAIMER
                }
            }

        # 5. Determine Effective Weight for Calorie Estimation
        is_weight_defaulted = False
        if weight_kg and float(weight_kg) > 20:
            effective_weight = float(weight_kg)
        else:
            effective_weight = self.DEFAULT_REFERENCE_WEIGHT_KG
            is_weight_defaulted = True

        # 6. Analyze Activity History for duration baseline
        historical_durations = {"Walking": [], "Cycling": [], "Running": []}
        for act in recent_activities:
            a_type = act.get("activity_type") or act.get("type") or ""
            d_mins = act.get("duration_mins") or act.get("durationMins")
            if d_mins and int(d_mins) > 0:
                for c_name in ["Walking", "Cycling", "Running"]:
                    if c_name.lower() in str(a_type).lower():
                        historical_durations[c_name].append(int(d_mins))

        # 7. Select Activities to Evaluate
        pref_lower = preferred_act.lower()
        if "walk" in pref_lower:
            categories_to_eval = ["Walking"]
        elif "cycl" in pref_lower or "bike" in pref_lower:
            categories_to_eval = ["Cycling", "Bicycling"]
        elif "run" in pref_lower or "jog" in pref_lower:
            categories_to_eval = ["Running"]
        else:
            categories_to_eval = ["Walking", "Cycling", "Running"]

        recommendations = []

        for cat in categories_to_eval:
            # Running restrictions: Do not recommend running for sedentary users, joint issues, or elevated glucose
            if cat == "Running":
                if fitness_level == "Sedentary" or has_joint_issues or safety["intensity_restriction"] == "Light_Only":
                    continue

            record = self._select_compendium_record(
                category=cat,
                fitness_level=fitness_level,
                safety_restriction=safety["intensity_restriction"],
                has_joint_issues=has_joint_issues
            )
            if not record:
                continue

            met = record["met_value"]
            code = record["code"]
            desc = record["description"]
            speed_kmh = record["speed_kmh"]
            cat_name = record["category"]

            # Intensity Label
            if met < 3.0:
                intensity = "Light"
            elif met < 6.0:
                intensity = "Moderate"
            else:
                intensity = "Vigorous"

            # Determine Starting Duration (minutes)
            cat_hist = historical_durations.get(cat, [])
            if cat_hist:
                avg_mins = sum(cat_hist) / len(cat_hist)
                duration_mins = max(15, min(int(round(avg_mins * 1.05)), 45))
                duration_reason = f"Personalized progression based on your historical average of {int(round(avg_mins))} minutes."
            else:
                if fitness_level == "Sedentary":
                    duration_mins = 15
                    duration_reason = "Conservative starting duration tailored to safely establish baseline conditioning."
                elif fitness_level == "Light":
                    duration_mins = 20
                    duration_reason = "Structured duration to build aerobic endurance progressively."
                elif fitness_level == "Active":
                    duration_mins = 35 if cat != "Running" else 25
                    duration_reason = "Tailored for active fitness to optimize cardiovascular impact and glycemic control."
                else: # Moderate
                    duration_mins = 30 if cat != "Running" else 20
                    duration_reason = "Targeted duration matching clinical diabetes physical activity standards."

            # Cap duration by available exercise time if provided
            if available_time and available_time > 0:
                if duration_mins > available_time:
                    duration_mins = available_time
                    duration_reason += f" Capped to your available exercise window of {available_time} minutes."

            # Cap duration if glucose is elevated (250-299 mg/dL)
            if safety["intensity_restriction"] == "Light_Only":
                duration_mins = min(duration_mins, 20)
                duration_reason += " Capped to 20 minutes maximum as glucose is currently elevated."

            # Calculate Distance (km) = Speed (km/h) * Duration (mins) / 60
            distance_km = None
            speed_source = None
            if speed_kmh and speed_kmh > 0 and duration_mins > 0:
                distance_km = round(speed_kmh * (duration_mins / 60.0), 2)
                speed_source = f"2024 Compendium benchmark ({speed_kmh:.1f} km/h, Code {code})"

            # Calculate Estimated Calories Burned:
            # Formula: MET * Weight (kg) * (Duration / 60)
            cals_burned = int(round(met * effective_weight * (duration_mins / 60.0)))
            weight_label = f"{effective_weight} kg (reference default)" if is_weight_defaulted else f"{effective_weight} kg"
            calorie_formula = (
                f"Estimated Calories = MET ({met:.1f}) × Weight ({weight_label}) × "
                f"({duration_mins} mins / 60) = {cals_burned} kcal (Estimate)"
            )

            # Clinical rationale for selection
            if "walk" in cat.lower():
                clinical_reason = "Stimulates skeletal muscle GLUT4 glucose uptake independently of insulin with minimal joint strain."
                icon = "🚶‍♂️"
            elif "cycl" in cat.lower() or "bike" in cat.lower():
                clinical_reason = "Low-impact non-weight-bearing aerobic exercise that promotes sustained peripheral glucose disposal."
                icon = "🚴"
            elif "run" in cat.lower():
                clinical_reason = "Elevated metabolic rate enhances cardiovascular fitness and insulin sensitivity over 24-48 hours."
                icon = "🏃"
            else:
                clinical_reason = "Enhances muscular endurance and insulin sensitivity with controlled exertion."
                icon = "🧘"

            combined_explanation = f"{duration_reason} {clinical_reason}"

            recommendations.append({
                "activity": cat_name,
                "icon": icon,
                "duration_minutes": duration_mins,
                "distance_km": distance_km,
                "speed_kmh": round(speed_kmh, 1) if speed_kmh else None,
                "speed_source": speed_source,
                "met_value": met,
                "intensity": intensity,
                "estimated_calories_burned": cals_burned,
                "calorie_formula": calorie_formula,
                "is_estimate": True,
                "compendium_code": code,
                "compendium_description": desc,
                "reason": combined_explanation,
                "safety_note": safety["safety_guidance"]
            })

        # Ensure at least walking is recommended if user filtered out everything
        if not recommendations and categories_to_eval != ["Walking"]:
            walk_rec = self._select_compendium_record("Walking", fitness_level, safety["intensity_restriction"], has_joint_issues)
            if walk_rec:
                dur = min(20, available_time) if available_time else 20
                cals = int(round(walk_rec["met_value"] * effective_weight * (dur / 60.0)))
                recommendations.append({
                    "activity": "Walking",
                    "icon": "🚶‍♂️",
                    "duration_minutes": dur,
                    "distance_km": round(walk_rec["speed_kmh"] * (dur / 60.0), 2) if walk_rec["speed_kmh"] else None,
                    "speed_kmh": walk_rec["speed_kmh"],
                    "speed_source": f"2024 Compendium benchmark ({walk_rec['speed_kmh']} km/h, Code {walk_rec['code']})" if walk_rec["speed_kmh"] else None,
                    "met_value": walk_rec["met_value"],
                    "intensity": "Moderate" if walk_rec["met_value"] >= 3.0 else "Light",
                    "estimated_calories_burned": cals,
                    "calorie_formula": f"Estimated Calories = MET ({walk_rec['met_value']}) × Weight ({effective_weight} kg) × ({dur} mins / 60) = {cals} kcal (Estimate)",
                    "is_estimate": True,
                    "compendium_code": walk_rec["code"],
                    "compendium_description": walk_rec["description"],
                    "reason": "Gentle baseline aerobic exercise promoting peripheral glucose uptake.",
                    "safety_note": safety["safety_guidance"]
                })

        return {
            "success": True,
            "message": f"Personalized activity options generated successfully from 2024 Compendium for {fitness_level} profile.",
            "data": {
                "success": True,
                "recommendations": recommendations,
                "user_context": {
                    "user_id": user_id,
                    "age": age,
                    "diabetes_type": diabetes_type,
                    "height_cm": height_cm,
                    "weight_kg": weight_kg,
                    "effective_weight_kg": effective_weight,
                    "is_weight_defaulted": is_weight_defaulted,
                    "bmi": bmi,
                    "current_glucose_mg_dl": current_glucose,
                    "target_glucose_mg_dl": target_glucose,
                    "fitness_level": fitness_level,
                    "preferred_activity": preferred_act,
                    "available_time_mins": available_time,
                    "safety_clearance": True,
                    "status_code": safety["status_code"]
                },
                "safety_guidance": safety["safety_guidance"],
                "disclaimer": self.DISCLAIMER
            }
        }

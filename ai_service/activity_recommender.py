"""
DiaWell AI Activity Recommendation Engine
Evidence-informed personalized physical activity recommendations for diabetes management
Utilizes the 2024 Adult Compendium of Physical Activities (walking_running_cycling.csv)
"""

import os
import re
import math
from typing import Dict, List, Optional, Any, Tuple
import pandas as pd


class CompendiumDatasetManager:
    """Manages loading and querying of the 2024 Adult Compendium dataset."""

    def __init__(self, csv_path: str):
        self.csv_path = csv_path
        self.df: Optional[pd.DataFrame] = None
        self._load_dataset()

    def _load_dataset(self):
        """Loads and normalizes the compendium CSV."""
        if not os.path.exists(self.csv_path):
            print(f"Warning: Compendium dataset not found at {self.csv_path}")
            self.df = None
            return

        try:
            df = pd.read_csv(self.csv_path)
            # Ensure proper column names
            df.columns = [c.strip().lower() for c in df.columns]
            # Standard columns: activity_category, activity_code, met_value, activity_description
            if 'met_value' in df.columns:
                df['met_value'] = pd.to_numeric(df['met_value'], errors='coerce')
            if 'activity_code' in df.columns:
                df['activity_code'] = pd.to_numeric(df['activity_code'], errors='coerce')
            if 'activity_category' in df.columns:
                df['activity_category'] = df['activity_category'].astype(str).str.strip()
            if 'activity_description' in df.columns:
                df['activity_description'] = df['activity_description'].astype(str).str.strip()

            self.df = df
            print(f"Successfully loaded Compendium dataset with {len(df)} records from {self.csv_path}")
        except Exception as e:
            print(f"Error reading compendium CSV: {e}")
            self.df = None

    def get_by_category(self, category: str) -> pd.DataFrame:
        if self.df is None or self.df.empty:
            return pd.DataFrame()
        cat_lower = category.lower()
        if cat_lower in ["cycling", "bicycling"]:
            return self.df[self.df['activity_category'].str.lower().isin(["bicycling", "cycling"])]
        return self.df[self.df['activity_category'].str.lower() == cat_lower]


class ActivityRecommendationEngine:
    """
    Evaluates patient profile, glucose status, fitness level, and history
    to generate individualized exercise intensity, duration, and distance.
    """

    def __init__(self, dataset_manager: CompendiumDatasetManager):
        self.dm = dataset_manager

    @staticmethod
    def _calculate_bmi(height_cm: Optional[float], weight_kg: Optional[float]) -> Optional[float]:
        """Calculates BMI only when height and weight are valid positive numbers."""
        if height_cm and weight_kg and height_cm > 50 and weight_kg > 20:
            height_m = height_cm / 100.0
            return round(weight_kg / (height_m * height_m), 1)
        return None

    @staticmethod
    def _extract_speed_kmh_from_description(desc: str) -> Optional[float]:
        """
        Extracts validated speed in km/h from compendium description text if present.
        E.g. 'Walking, 3.0 mph' -> 4.83 km/h
        E.g. '4.0 to 4.4 mph (6.4 to 7.0 km/h)' -> 6.7 km/h
        """
        # Look for explicit km/h range or value first
        kmh_match = re.search(r'(\d+(?:\.\d+)?)\s*(?:to|-)\s*(\d+(?:\.\d+)?)\s*km/h', desc, re.IGNORECASE)
        if kmh_match:
            v1 = float(kmh_match.group(1))
            v2 = float(kmh_match.group(2))
            return round((v1 + v2) / 2.0, 1)

        single_kmh = re.search(r'(\d+(?:\.\d+)?)\s*km/h', desc, re.IGNORECASE)
        if single_kmh:
            return round(float(single_kmh.group(1)), 1)

        # Look for mph range or value
        mph_match = re.search(r'(\d+(?:\.\d+)?)\s*(?:to|-)\s*(\d+(?:\.\d+)?)\s*mph', desc, re.IGNORECASE)
        if mph_match:
            v1 = float(mph_match.group(1))
            v2 = float(mph_match.group(2))
            avg_mph = (v1 + v2) / 2.0
            return round(avg_mph * 1.60934, 1)

        single_mph = re.search(r'(\d+(?:\.\d+)?)\s*mph', desc, re.IGNORECASE)
        if single_mph:
            return round(float(single_mph.group(1)) * 1.60934, 1)

        return None

    def _determine_glucose_safety(
        self,
        current_glucose: Optional[float],
        reading_type: Optional[str],
        medications: List[str]
    ) -> Dict[str, Any]:
        """
        Assesses clinical safety before exercise based on ADA guidelines.
        Never prescribes exercise without safety alerts if glucose is out-of-range.
        """
        has_hypo_med = any(
            any(w in m.lower() for w in ['insulin', 'glimepiride', 'glipizide', 'gliclazide', 'sulfonylurea'])
            for m in medications
        )

        if current_glucose is None:
            return {
                "safe_to_exercise": True,
                "intensity_restriction": "Moderate",
                "status_code": "NO_RECENT_READING",
                "alert": None,
                "safety_note": "No recent glucose reading available. Check blood glucose before and after your workout. Keep hydration and fast-acting carbs on hand."
            }

        # Severe Hypoglycemia (< 70 mg/dL)
        if current_glucose < 70:
            return {
                "safe_to_exercise": False,
                "intensity_restriction": "Contraindicated",
                "status_code": "HYPOGLYCEMIA_SEVERE",
                "alert": f"Blood glucose is currently low ({current_glucose:.0f} mg/dL). Exercise is contraindicated.",
                "safety_note": "Do not exercise while hypoglycemic. Immediately consume 15–20g of fast-acting carbohydrate (e.g. 1/2 cup fruit juice, 4 glucose tablets). Recheck glucose in 15 minutes and follow clinician protocol."
            }

        # Mild Hypoglycemia / Borderline Low (< 100 mg/dL)
        if current_glucose < 100:
            return {
                "safe_to_exercise": True,
                "intensity_restriction": "Light",
                "status_code": "HYPOGLYCEMIA_RISK",
                "alert": f"Pre-exercise glucose is below 100 mg/dL ({current_glucose:.0f} mg/dL).",
                "safety_note": "Consume a small snack containing 15g of carbohydrates (e.g., fruit or whole grain biscuit) before starting. Recheck glucose to verify it is above 100 mg/dL before initiating moderate exertion."
            }

        # Safe pre-exercise window (100 - 249 mg/dL)
        if current_glucose <= 250:
            med_warning = (
                " Note: As you take insulin or sulfonylurea therapy, always carry fast-acting glucose (e.g. 15g glucose tablets or juice) during workouts."
                if has_hypo_med else " Stay well hydrated before, during, and after activity."
            )
            return {
                "safe_to_exercise": True,
                "intensity_restriction": "None",
                "status_code": "SAFE_WINDOW",
                "alert": None,
                "safety_note": f"Current glucose ({current_glucose:.0f} mg/dL) is within the safe pre-exercise range.{med_warning}"
            }

        # Significant Hyperglycemia (250 - 299 mg/dL)
        if current_glucose < 300:
            return {
                "safe_to_exercise": True,
                "intensity_restriction": "Light_Only",
                "status_code": "HYPERGLYCEMIA_CAUTION",
                "alert": f"Blood glucose is elevated ({current_glucose:.0f} mg/dL). Vigorous workouts are contraindicated.",
                "safety_note": "Avoid strenuous running or fast cycling as catecholamine release can worsen hyperglycemia. Only gentle, low-intensity walking with plenty of water is advised if asymptomatic and ketones are negative."
            }

        # Severe Hyperglycemia (>= 300 mg/dL)
        return {
            "safe_to_exercise": False,
            "intensity_restriction": "Contraindicated",
            "status_code": "HYPERGLYCEMIA_SEVERE",
            "alert": f"Blood glucose is markedly elevated ({current_glucose:.0f} mg/dL). Exercise is contraindicated.",
            "safety_note": "Do not exercise at this glucose level. There is risk of dehydration and ketoacidosis. Drink water, check urine/blood ketones, take prescribed correction, and contact your healthcare provider."
        }

    def _select_compendium_activity(
        self,
        category: str,
        fitness_level: str,
        safety: Dict[str, Any],
        has_joint_limitations: bool
    ) -> Optional[Dict[str, Any]]:
        """
        Matches an evidence-informed activity record from the 2024 Compendium
        based on category, user fitness tier, and physical/safety constraints.
        """
        cat_df = self.dm.get_by_category(category)
        if cat_df.empty:
            return None

        # Clean descriptions
        clean_df = cat_df[~cat_df['activity_description'].str.contains(
            'backpacking|carrying 15|stairs|snow|sand|plowed|silly walks|backward',
            case=False, na=False
        )].copy()

        if clean_df.empty:
            clean_df = cat_df.copy()

        level_lower = fitness_level.lower()

        # Selection per category:
        if category == "Walking":
            if safety["intensity_restriction"] in ["Light", "Light_Only"] or "sedentary" in level_lower:
                # Moderate/light stroll ~ 2.5 - 3.0 MET
                target_code = 17170 # Walking, 2.5 mph, firm, level surface, MET 3.0
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 2.5) & (clean_df['met_value'] <= 3.2)]
                speed_kmh = 4.0
            elif "active" in level_lower or "high" in level_lower:
                # Brisk power walk ~ 4.8 MET
                target_code = 17200 # Walking, 3.5 to 3.9 mph, level, brisk, MET 4.8
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 4.5) & (clean_df['met_value'] <= 5.5)]
                speed_kmh = 5.8
            else: # Moderate
                # Level moderate walk ~ 3.8 MET
                target_code = 17190 # Walking, 2.8 to 3.4 mph, level, moderate pace, MET 3.8
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 3.5) & (clean_df['met_value'] <= 4.2)]
                speed_kmh = 4.8

        elif category in ["Bicycling", "Cycling"]:
            if safety["intensity_restriction"] in ["Light", "Light_Only"] or "sedentary" in level_lower:
                # Leisure cycling ~ 4.0 MET
                target_code = 1010 # Bicycling, <10 mph, leisure, MET 4.0
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 3.5) & (clean_df['met_value'] <= 4.5)]
                speed_kmh = 14.5
            elif "active" in level_lower or "high" in level_lower:
                # Fast cycling ~ 8.0 MET
                target_code = 1020 # Bicycling, 12-13.9 mph, moderate effort, MET 8.0
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 7.5) & (clean_df['met_value'] <= 9.0)]
                speed_kmh = 20.0
            else: # Moderate
                # Commuting / moderate cycling ~ 6.8 MET
                target_code = 1011 # Bicycling, to/from work, self selected pace, MET 6.8
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 5.5) & (clean_df['met_value'] <= 7.0)]
                speed_kmh = 17.5

        else: # Running
            # Low fitness or joint pain: do not recommend vigorous running!
            if "sedentary" in level_lower or has_joint_limitations or safety["intensity_restriction"] != "None":
                # Light jog/walk interval ~ 6.0 MET or contraindicated
                target_code = 12010 # Jog/walk combination, MET 6.0
                match = clean_df[clean_df['activity_code'] == target_code]
                speed_kmh = 6.0
            elif "active" in level_lower or "high" in level_lower:
                # Running 5.5 - 6.0 mph ~ 9.0 - 9.3 MET
                target_code = 12050 # Running, 6-6.3 mph (10 min/mile), MET 9.3
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 8.5) & (clean_df['met_value'] <= 10.0)]
                speed_kmh = 9.8
            else: # Moderate
                # Jogging general ~ 7.5 MET
                target_code = 12020 # Jogging, general, self-selected pace, MET 7.5
                match = clean_df[clean_df['activity_code'] == target_code]
                if match.empty:
                    match = clean_df[(clean_df['met_value'] >= 6.5) & (clean_df['met_value'] <= 8.0)]
                speed_kmh = 7.5

        if match.empty:
            row = clean_df.iloc[0]
        else:
            row = match.iloc[0]

        met_val = float(row['met_value'])
        desc = str(row['activity_description'])
        code = int(row['activity_code'])

        # Extracted speed from description if present
        parsed_speed = self._extract_speed_kmh_from_description(desc)
        final_speed = parsed_speed if parsed_speed else speed_kmh

        return {
            "code": code,
            "met_value": met_val,
            "description": desc,
            "speed_kmh": final_speed
        }

    def generate_recommendations(
        self,
        patient_data: Dict[str, Any]
    ) -> Dict[str, Any]:
        """
        Core recommendation pipeline.
        Considers patient profile, glucose readings, activity history, physical constraints,
        and generates personalized starting duration & validated distance.
        """
        user_id = patient_data.get("user_id")
        age = patient_data.get("age")
        height_cm = patient_data.get("height_cm")
        weight_kg = patient_data.get("weight_kg")
        current_glucose = patient_data.get("current_glucose_mg_dl")
        target_glucose = patient_data.get("target_glucose_mg_dl") or 120.0
        reading_type = patient_data.get("reading_type")
        reading_date = patient_data.get("reading_date")
        reading_time = patient_data.get("reading_time")
        fitness_level = patient_data.get("fitness_level") or patient_data.get("activity_level") or "Moderate"
        preferred_activity = patient_data.get("preferred_activity") or "All"
        available_time = patient_data.get("available_time_mins")
        limitations = patient_data.get("physical_limitations") or []
        medications = patient_data.get("medications") or []
        recent_activities = patient_data.get("recent_activities") or []

        # 1. Calculate BMI
        bmi = self._calculate_bmi(height_cm, weight_kg)

        # 2. Check clinical exercise safety
        safety = self._determine_glucose_safety(current_glucose, reading_type, medications)

        # 3. Check physical limitations
        has_joint_issues = any(
            any(w in str(lim).lower() for w in ['knee', 'joint', 'arthritis', 'foot', 'ulcer', 'neuropathy', 'back', 'hip'])
            for lim in limitations
        )

        # 4. Analyze previous history for historical pace and duration
        historical_durations: Dict[str, List[int]] = {"Walking": [], "Cycling": [], "Running": []}
        historical_speeds: Dict[str, List[float]] = {"Walking": [], "Cycling": [], "Running": []}

        for act in recent_activities:
            a_type = act.get("activity_type") or act.get("type") or ""
            d_mins = act.get("duration_mins") or act.get("durationMins")
            dist_km = act.get("distance_km") or act.get("distanceKm")

            for cat_key in ["Walking", "Cycling", "Running"]:
                if cat_key.lower() in a_type.lower():
                    if d_mins and d_mins > 0:
                        historical_durations[cat_key].append(int(d_mins))
                        if dist_km and dist_km > 0:
                            # Speed = dist / (mins / 60)
                            calc_speed = float(dist_km) / (float(d_mins) / 60.0)
                            if 2.0 <= calc_speed <= 35.0: # sanity bounds
                                historical_speeds[cat_key].append(calc_speed)

        # 5. Evaluate activities
        categories_to_evaluate = ["Walking", "Cycling", "Running"]
        if preferred_activity and preferred_activity.lower() != "all":
            # If user explicitly preferred an activity, keep it first
            categories_to_evaluate.sort(key=lambda c: 0 if c.lower() in preferred_activity.lower() else 1)

        recommendations = []

        # If exercise is strictly contraindicated due to severe hypoglycemia or hyperglycemia
        if not safety["safe_to_exercise"]:
            return {
                "success": False,
                "recommendations": [],
                "user_context": {
                    "user_id": user_id,
                    "age": age,
                    "height_cm": height_cm,
                    "weight_kg": weight_kg,
                    "bmi": bmi,
                    "current_glucose_mg_dl": current_glucose,
                    "target_glucose_mg_dl": target_glucose,
                    "reading_type": reading_type,
                    "fitness_level": fitness_level,
                    "safety_clearance": False
                },
                "safety_guidance": safety["alert"],
                "message": safety["safety_note"]
            }

        effective_weight = weight_kg if (weight_kg and weight_kg > 30) else 68.0

        for category in categories_to_evaluate:
            # Check if running should be omitted or adjusted
            is_running = (category == "Running")
            if is_running and (has_joint_issues or "sedentary" in fitness_level.lower()):
                # Do not recommend vigorous running automatically to users with low fitness or joint limitations!
                # We can either skip or offer very conservative light jog/walk intervals
                if "sedentary" in fitness_level.lower():
                    continue

            # Query compendium
            comp_match = self._select_compendium_activity(
                category=category,
                fitness_level=fitness_level,
                safety=safety,
                has_joint_limitations=has_joint_issues
            )

            if not comp_match:
                continue

            met = comp_match["met_value"]
            comp_code = comp_match["code"]
            comp_desc = comp_match["description"]
            validated_speed = comp_match["speed_kmh"]

            # Intensity classification based on MET values
            if met < 3.0:
                intensity_label = "Light"
            elif met < 6.0:
                intensity_label = "Moderate"
            else:
                intensity_label = "Vigorous"

            # Determine personalized duration (minutes)
            cat_history = historical_durations.get(category, [])
            if cat_history:
                avg_hist_mins = sum(cat_history) / len(cat_history)
                # Gradual progression: +5% to +10% over historical baseline, capped sensibly
                suggested_duration = int(round(avg_hist_mins * 1.05))
                # Ensure minimum 15 mins, maximum 45 mins for safety
                suggested_duration = max(15, min(suggested_duration, 45))
                reason_duration = f"Adapted from your average workout history of {int(round(avg_hist_mins))} minutes with gentle progression."
            else:
                # Conservative starting durations based on fitness tier
                tier = fitness_level.lower()
                if "sedentary" in tier:
                    suggested_duration = 15 if category != "Running" else 10
                    reason_duration = "Conservative starting duration tailored for initial aerobic conditioning."
                elif "light" in tier:
                    suggested_duration = 20
                    reason_duration = "Tailored for light baseline fitness to build steady endurance."
                elif "active" in tier or "high" in tier:
                    suggested_duration = 35 if category != "Running" else 25
                    reason_duration = "Personalized for higher baseline aerobic capacity and sustained metabolic impact."
                else: # Moderate
                    suggested_duration = 25 if category != "Running" else 20
                    reason_duration = "Targeted duration matching moderate physical activity recommendations."

            # Cap by user's available time if explicitly passed
            if available_time and available_time > 0:
                if suggested_duration > available_time:
                    suggested_duration = available_time
                    reason_duration += f" Capped to your available exercise window of {available_time} minutes."

            # Apply safety restriction: If glucose was high (250-299), cap to 20 mins light walk
            if safety["intensity_restriction"] == "Light_Only":
                suggested_duration = min(suggested_duration, 20)
                if intensity_label == "Vigorous":
                    continue # Do not include vigorous activities during elevated glucose

            # Calculate personalized distance:
            # Distance (km) = Speed (km/h) * Duration (minutes) / 60
            cat_speeds = historical_speeds.get(category, [])
            if cat_speeds:
                avg_speed = sum(cat_speeds) / len(cat_speeds)
                speed_source = f"Personal historical average pace ({avg_speed:.1f} km/h)"
                speed_used = avg_speed
            elif validated_speed:
                speed_source = f"Compendium validated benchmark ({validated_speed:.1f} km/h, Code {comp_code})"
                speed_used = validated_speed
            else:
                speed_source = None
                speed_used = None

            if speed_used and speed_used > 0 and suggested_duration > 0:
                distance_km = round(speed_used * (suggested_duration / 60.0), 2)
            else:
                distance_km = None
                speed_source = "Pace cannot be estimated reliably without prior speed data."

            # Calculate estimated calories burned:
            # Calories = MET * Weight (kg) * (Duration / 60)
            calories_burned = int(round(met * effective_weight * (suggested_duration / 60.0)))

            # Activity icon
            icon = "🚶‍♂️" if category == "Walking" else ("🚴" if category in ["Cycling", "Bicycling"] else "🏃")

            # Clinical rationale
            if category == "Walking":
                clinical_reason = "Activates skeletal muscle glucose uptake via insulin-independent GLUT4 translocation with minimal joint strain."
            elif category in ["Cycling", "Bicycling"]:
                clinical_reason = "Low-impact non-weight-bearing aerobic exercise that promotes sustained peripheral glucose clearance."
            else:
                clinical_reason = "Elevated metabolic rate enhances cardiovascular fitness and insulin sensitivity over 24-48 hours."

            combined_reason = f"{reason_duration} {clinical_reason}"

            recommendations.append({
                "activity": category,
                "icon": icon,
                "duration_minutes": suggested_duration,
                "distance_km": distance_km,
                "speed_kmh": round(speed_used, 1) if speed_used else None,
                "speed_source": speed_source,
                "met_value": met,
                "intensity": intensity_label,
                "estimated_calories_burned": calories_burned,
                "compendium_code": comp_code,
                "compendium_description": comp_desc,
                "reason": combined_reason,
                "safety_note": safety["safety_note"]
            })

        return {
            "success": True,
            "recommendations": recommendations,
            "user_context": {
                "user_id": user_id,
                "age": age,
                "height_cm": height_cm,
                "weight_kg": weight_kg,
                "bmi": bmi,
                "current_glucose_mg_dl": current_glucose,
                "target_glucose_mg_dl": target_glucose,
                "reading_type": reading_type,
                "reading_date": reading_date,
                "reading_time": reading_time,
                "fitness_level": fitness_level,
                "safety_clearance": True,
                "status_code": safety["status_code"]
            },
            "safety_guidance": "Always monitor symptoms. Stay hydrated, wear appropriate footwear, and cease activity immediately if you experience dizziness, lightheadedness, or palpitations.",
            "message": f"Personalized activity options generated successfully from 2024 Compendium for {fitness_level} profile."
        }

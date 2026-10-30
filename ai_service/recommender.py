"""
DiaWell AI Nutrition Recommender Engine & Dataset Manager
Dataset: Indian_Food_Nutrition_Processed.csv (1014 dishes)
Evidence-informed nutritional analysis for Diabetes Management
"""

import os
import re
import math
from typing import Dict, List, Optional, Any, Tuple
import pandas as pd


# Canonical column names matching Indian_Food_Nutrition_Processed.csv
COL_DISH = "Dish Name"
COL_CALORIES = "Calories (kcal)"
COL_CARBS = "Carbohydrates (g)"
COL_PROTEIN = "Protein (g)"
COL_FATS = "Fats (g)"
COL_SUGAR = "Free Sugar (g)"
COL_FIBRE = "Fibre (g)"
COL_SODIUM = "Sodium (mg)"
COL_CALCIUM = "Calcium (mg)"
COL_IRON = "Iron (mg)"
COL_VIT_C = "Vitamin C (mg)"
COL_FOLATE = "Folate (µg)"

NON_VEG_KEYWORDS = [
    "chicken", "mutton", "fish", "egg", "prawn", "keema", "gosht",
    "meat", "crab", "pork", "beef", "shrimp", "duck", "pomfret",
    "salmon", "tuna", "seafood", "omlet", "omelette", "bhurji", "tikka (chicken)"
]

DAIRY_KEYWORDS = [
    "paneer", "milk", "curd", "dahi", "cheese", "butter", "ghee",
    "malai", "lassi", "raita", "khoya", "cream", "kheer", "rabri"
]

BEVERAGE_KEYWORDS = [
    "tea", "chai", "coffee", "cooler", "juice", "drink", "shake",
    "smoothie", "water", "punch", "lemonade", "cocoa"
]

BREAKFAST_KEYWORDS = [
    "oats", "idli", "dosa", "poha", "upma", "paratha", "pancake",
    "toast", "cornflakes", "porridge", "thepla", "cheela", "chilla", "puri", "upama"
]

SNACK_KEYWORDS = [
    "snack", "chaat", "tikki", "pakoda", "pakora", "samosa", "cutlet",
    "biscuit", "cookie", "roll", "kebab", "vada", "bhel", "chivda",
    "sprouts", "salad", "soup"
]


class NutritionDatasetManager:
    """
    Manages loading, validating, cleaning, and querying the Indian Food Nutrition Dataset.
    Preserves actual dataset values without inventing numbers or substituting zero for missing entries.
    """

    def __init__(self, csv_path: str):
        self.csv_path = csv_path
        self.df: Optional[pd.DataFrame] = None
        self.load_dataset()

    def _normalize_columns(self, df: pd.DataFrame) -> pd.DataFrame:
        """Normalizes column names to standard keys and resolves character anomalies."""
        mapping = {}
        for col in df.columns:
            clean = col.strip()
            if "Dish" in clean:
                mapping[col] = COL_DISH
            elif "Calorie" in clean:
                mapping[col] = COL_CALORIES
            elif "Carb" in clean:
                mapping[col] = COL_CARBS
            elif "Protein" in clean:
                mapping[col] = COL_PROTEIN
            elif "Fat" in clean and "Free" not in clean:
                mapping[col] = COL_FATS
            elif "Free Sugar" in clean or "Sugar" in clean:
                mapping[col] = COL_SUGAR
            elif "Fibre" in clean or "Fiber" in clean:
                mapping[col] = COL_FIBRE
            elif "Sodium" in clean:
                mapping[col] = COL_SODIUM
            elif "Calcium" in clean:
                mapping[col] = COL_CALCIUM
            elif "Iron" in clean:
                mapping[col] = COL_IRON
            elif "Vitamin C" in clean or "Vit C" in clean:
                mapping[col] = COL_VIT_C
            elif "Folate" in clean:
                mapping[col] = COL_FOLATE
            else:
                mapping[col] = clean
        df = df.rename(columns=mapping)
        return df

    def load_dataset(self):
        """Loads and prepares the dataset using pandas."""
        if not os.path.exists(self.csv_path):
            raise FileNotFoundError(f"Nutrition dataset not found at: {self.csv_path}")

        try:
            df = pd.read_csv(self.csv_path, encoding="utf-8")
        except UnicodeDecodeError:
            df = pd.read_csv(self.csv_path, encoding="latin1")

        df = self._normalize_columns(df)

        if COL_DISH not in df.columns:
            raise ValueError(f"Required column '{COL_DISH}' not found in CSV. Found: {list(df.columns)}")

        # Clean dish name strings
        df[COL_DISH] = df[COL_DISH].astype(str).str.strip()
        df = df.drop_duplicates(subset=[COL_DISH], keep="first")

        # Assign numeric ID starting from 1001 for seamless Android ID routing
        df["item_id"] = range(1001, 1001 + len(df))

        # Add automated classifications
        df["diet_type"] = df[COL_DISH].apply(self._infer_diet_type)
        df["category"] = df[COL_DISH].apply(self._infer_category)

        # Record missing nutrients per row without inventing zeros
        nutrient_cols = [
            COL_CALORIES, COL_CARBS, COL_PROTEIN, COL_FATS,
            COL_SUGAR, COL_FIBRE, COL_SODIUM, COL_CALCIUM,
            COL_IRON, COL_VIT_C, COL_FOLATE
        ]
        available_cols = [c for c in nutrient_cols if c in df.columns]
        df["has_missing_nutrients"] = df[available_cols].isnull().any(axis=1)

        self.df = df
        print(f"[NutritionDatasetManager] Successfully loaded {len(self.df)} food records from {self.csv_path}")

    @staticmethod
    def _infer_diet_type(name: str) -> str:
        name_lower = name.lower()
        for kw in NON_VEG_KEYWORDS:
            if re.search(r"\b" + re.escape(kw) + r"\b", name_lower):
                return "Non-Vegetarian"
        return "Vegetarian"

    @staticmethod
    def _infer_category(name: str) -> str:
        name_lower = name.lower()
        for kw in BEVERAGE_KEYWORDS:
            if re.search(r"\b" + re.escape(kw) + r"\b", name_lower):
                return "Beverage"
        for kw in BREAKFAST_KEYWORDS:
            if re.search(r"\b" + re.escape(kw) + r"\b", name_lower):
                return "Breakfast"
        for kw in SNACK_KEYWORDS:
            if re.search(r"\b" + re.escape(kw) + r"\b", name_lower):
                return "Snack"
        return "Lunch/Dinner"

    def get_stats(self) -> Dict[str, Any]:
        """Returns metadata stats about the loaded dataset."""
        if self.df is None:
            return {"status": "unloaded"}
        return {
            "total_dishes": int(len(self.df)),
            "diet_type_counts": self.df["diet_type"].value_counts().to_dict(),
            "category_counts": self.df["category"].value_counts().to_dict(),
            "columns": list(self.df.columns),
            "csv_path": self.csv_path
        }

    def search_dishes(
        self,
        query: Optional[str] = None,
        category: Optional[str] = None,
        diet_type: Optional[str] = None,
        max_carbs: Optional[float] = None,
        min_protein: Optional[float] = None,
        max_sugar: Optional[float] = None,
        max_calories: Optional[float] = None,
        sort_by: str = "name",
        sort_order: str = "asc",
        page: int = 1,
        limit: int = 30
    ) -> Dict[str, Any]:
        """Case-insensitive partial match search for Food Explorer with filters and pagination."""
        if self.df is None:
            return {"total": 0, "page": page, "limit": limit, "items": []}

        filtered = self.df.copy()

        if query and query.strip():
            q_clean = query.strip().lower()
            filtered = filtered[filtered[COL_DISH].str.lower().str.contains(q_clean, na=False)]

        if category and category.strip() and category.lower() != "all":
            c_clean = category.strip().lower()
            filtered = filtered[filtered["category"].str.lower().str.contains(c_clean, na=False)]

        if diet_type and diet_type.strip():
            d_clean = diet_type.strip().lower()
            if "non" in d_clean:
                pass  # Non-veg users can view all meals
            elif "veg" in d_clean:
                filtered = filtered[filtered["diet_type"] == "Vegetarian"]

        if max_carbs is not None and COL_CARBS in filtered.columns:
            filtered = filtered[filtered[COL_CARBS] <= max_carbs]

        if min_protein is not None and COL_PROTEIN in filtered.columns:
            filtered = filtered[filtered[COL_PROTEIN] >= min_protein]

        if max_sugar is not None and COL_SUGAR in filtered.columns:
            filtered = filtered[filtered[COL_SUGAR] <= max_sugar]

        if max_calories is not None and COL_CALORIES in filtered.columns:
            filtered = filtered[filtered[COL_CALORIES] <= max_calories]

        # Sorting
        sort_col_map = {
            "name": COL_DISH,
            "calories": COL_CALORIES,
            "carbs": COL_CARBS,
            "protein": COL_PROTEIN,
            "fibre": COL_FIBRE,
            "sugar": COL_SUGAR,
            "sodium": COL_SODIUM
        }
        sort_target = sort_col_map.get(sort_by.lower(), COL_DISH)
        ascending = (sort_order.lower() == "asc")
        if sort_target in filtered.columns:
            filtered = filtered.sort_values(by=sort_target, ascending=ascending)

        total_count = len(filtered)
        start_idx = max(0, (page - 1) * limit)
        end_idx = start_idx + limit
        page_items = filtered.iloc[start_idx:end_idx]

        results = [self.row_to_dict(row) for _, row in page_items.iterrows()]

        return {
            "total": total_count,
            "page": page,
            "limit": limit,
            "total_pages": math.ceil(total_count / limit) if limit > 0 else 1,
            "has_next": end_idx < total_count,
            "has_prev": page > 1,
            "items": results
        }

    def get_by_name(self, dish_name: str) -> Optional[Dict[str, Any]]:
        """Finds a dish by exact or partial name."""
        if self.df is None or not dish_name:
            return None
        target = dish_name.strip().lower()
        match = self.df[self.df[COL_DISH].str.lower() == target]
        if match.empty:
            match = self.df[self.df[COL_DISH].str.lower().str.contains(re.escape(target), na=False)]
        if match.empty:
            return None
        return self.row_to_dict(match.iloc[0])

    def get_by_id(self, item_id: int) -> Optional[Dict[str, Any]]:
        """Finds a dish by generated item_id."""
        if self.df is None:
            return None
        match = self.df[self.df["item_id"] == item_id]
        if match.empty:
            return None
        return self.row_to_dict(match.iloc[0])

    @staticmethod
    def _safe_float(val: Any) -> Optional[float]:
        """Safely extracts float without inventing values."""
        if pd.isna(val) or val is None or val == "":
            return None
        try:
            return round(float(val), 2)
        except (ValueError, TypeError):
            return None

    def row_to_dict(self, row: pd.Series) -> Dict[str, Any]:
        """Converts a pandas row to a clean structured dictionary preserving real values."""
        calories_val = self._safe_float(row.get(COL_CALORIES))
        return {
            "id": int(row["item_id"]),
            "title": str(row[COL_DISH]),
            "dish_name": str(row[COL_DISH]),
            "calories": calories_val,
            "calories_label": f"{calories_val} kcal" if calories_val is not None else "Unknown",
            "carbs_g": self._safe_float(row.get(COL_CARBS)),
            "protein_g": self._safe_float(row.get(COL_PROTEIN)),
            "fat_g": self._safe_float(row.get(COL_FATS)),
            "free_sugar_g": self._safe_float(row.get(COL_SUGAR)),
            "fibre_g": self._safe_float(row.get(COL_FIBRE)),
            "sodium_mg": self._safe_float(row.get(COL_SODIUM)),
            "calcium_mg": self._safe_float(row.get(COL_CALCIUM)),
            "iron_mg": self._safe_float(row.get(COL_IRON)),
            "vitamin_c_mg": self._safe_float(row.get(COL_VIT_C)),
            "folate_ug": self._safe_float(row.get(COL_FOLATE)),
            "category": str(row.get("category", "Lunch/Dinner")),
            "diet_type": str(row.get("diet_type", "Vegetarian")),
            "portion": "100 g portion basis",  # Standard catalog basis
            "has_missing_nutrients": bool(row.get("has_missing_nutrients", False)),
            "icon": "🥗" if row.get("diet_type") == "Vegetarian" else "🍗"
        }


# =========================================================================
# HEALTH-AWARE DIABETES RECOMMENDATION ENGINE
# =========================================================================

class DiabetesRecommendationEngine:
    """
    Evidence-informed clinical nutrition recommendation engine tailored for diabetes management.
    Evaluates patient profile (diabetes type, glucose reading & reading type, height/weight BMI, preferences, allergies).
    Ranks ALL suitable dishes from the complete Indian Food Dataset using actual nutritional values.
    """

    def __init__(self, dataset_manager: NutritionDatasetManager):
        self.dm = dataset_manager

    def recommend_for_patient(
        self,
        patient_profile: Dict[str, Any],
        limit: int = 10,
        category: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Ranks all items from dataset for patient health profile without requiring an exact search query.
        Returns top ranked food options with scores, nutritional highlights, simple explanations, and limitations.
        """
        if self.dm.df is None or len(self.dm.df) == 0:
            return {
                "recommendations": [],
                "daily_plan": {"breakfast": [], "lunch": [], "snack": [], "dinner": []},
                "metadata": {"count": 0, "message": "Dataset unavailable"},
                "disclaimer": "Informational guidance only."
            }

        df = self.dm.df.copy()

        # Extract patient health parameters
        diabetes_type = str(patient_profile.get("diabetes_type") or "Type 2").strip()
        current_glucose = patient_profile.get("current_glucose_mg_dl")
        target_glucose = patient_profile.get("target_glucose_mg_dl") or 120.0
        reading_type = patient_profile.get("reading_type")
        reading_date = patient_profile.get("reading_date")

        height_cm = patient_profile.get("height_cm")
        weight_kg = patient_profile.get("weight_kg")
        age = patient_profile.get("age")

        diet_pref = str(patient_profile.get("dietary_preferences") or patient_profile.get("preferred_diet") or "Non-Vegetarian").strip()
        allergies = patient_profile.get("allergies") or []
        if isinstance(allergies, str):
            allergies = [a.strip() for a in allergies.split(",") if a.strip()]
        foods_to_avoid = patient_profile.get("foods_to_avoid") or []
        if isinstance(foods_to_avoid, str):
            foods_to_avoid = [f.strip() for f in foods_to_avoid.split(",") if f.strip()]
        health_focus = str(patient_profile.get("health_focus") or "Glucose Control").strip()
        activity_level = str(patient_profile.get("activity_level") or "Moderate").strip()
        category_filter = category or patient_profile.get("category")

        # -------------------------------------------------------------
        # 1. BMI Calculation for Contextual Health Information (Not standalone rule)
        # -------------------------------------------------------------
        bmi = None
        bmi_category = None
        bmi_context_note = None
        if height_cm is not None and weight_kg is not None:
            try:
                h_val = float(height_cm)
                w_val = float(weight_kg)
                if h_val >= 80.0 and w_val >= 20.0:
                    h_m = h_val / 100.0
                    bmi = round(w_val / (h_m * h_m), 1)
                    if bmi < 18.5:
                        bmi_category = "Underweight"
                    elif bmi < 25.0:
                        bmi_category = "Normal"
                    elif bmi < 30.0:
                        bmi_category = "Overweight"
                    else:
                        bmi_category = "Obese"
                    bmi_context_note = f"BMI is {bmi} kg/m² ({bmi_category}), used as contextual metabolic background."
            except (ValueError, TypeError):
                bmi = None

        # -------------------------------------------------------------
        # 2. Glucose Reading Contextual Evaluation
        # (Point-in-time reference, never a standalone diagnosis)
        # -------------------------------------------------------------
        glucose_context_note = None
        glucose_status = "unrecorded"
        hypo_alert = None

        if current_glucose is not None:
            try:
                g_val = float(current_glucose)
                r_type_clean = str(reading_type or "General").capitalize()
                date_str = f" on {reading_date}" if reading_date else ""

                if g_val < 70.0:
                    glucose_status = "hypoglycemia"
                    hypo_alert = (
                        f"⚠️ CLINICAL ALERT: Recorded blood glucose is {g_val:.0f} mg/dL (Hypoglycemia). "
                        "Routine food recommendations do not apply during hypoglycemia. Please consume 15g of fast-acting carbohydrate "
                        "(e.g., 1/2 cup fruit juice or 3-4 glucose tablets) immediately and re-test in 15 minutes as directed by your healthcare team."
                    )
                elif "fasting" in r_type_clean.lower():
                    # Fasting thresholds (ADA: normal < 100, impaired 100-125, elevated >= 126)
                    if g_val >= 126.0:
                        glucose_status = "elevated"
                    elif g_val >= 100.0:
                        glucose_status = "borderline_fasting"
                    else:
                        glucose_status = "in_target"
                elif "post" in r_type_clean.lower() or "meal" in r_type_clean.lower():
                    # Post-prandial thresholds (ADA: < 140 normal, 140-199 elevated, >= 200 high)
                    if g_val >= 200.0:
                        glucose_status = "high"
                    elif g_val >= 140.0:
                        glucose_status = "elevated"
                    else:
                        glucose_status = "in_target"
                else:
                    if g_val >= 180.0:
                        glucose_status = "high"
                    elif g_val >= 140.0:
                        glucose_status = "elevated"
                    else:
                        glucose_status = "normal"

                glucose_context_note = (
                    f"{r_type_clean} blood glucose reading of {g_val:.0f} mg/dL{date_str} evaluated as a contextual indicator. "
                    "Single readings do not represent a diagnosis or medical prescription."
                )
            except (ValueError, TypeError):
                current_glucose = None

        # -------------------------------------------------------------
        # 3. Dietary Preference Filter (Strict)
        # -------------------------------------------------------------
        is_veg_only = "non" not in diet_pref.lower() and "veg" in diet_pref.lower()
        is_vegan = "vegan" in diet_pref.lower()

        if is_veg_only:
            df = df[df["diet_type"] == "Vegetarian"]
        if is_vegan:
            df = df[df["diet_type"] == "Vegetarian"]
            dairy_pattern = "|".join([r"\b" + re.escape(k) + r"\b" for k in DAIRY_KEYWORDS])
            df = df[~df[COL_DISH].str.lower().str.contains(dairy_pattern, regex=True, na=False)]

        # -------------------------------------------------------------
        # 4. Allergy / Avoidance Exclusion (Dish Name keyword check)
        # -------------------------------------------------------------
        exclusion_keywords = [a.lower() for a in (allergies + foods_to_avoid)]
        allergy_warnings = []
        if exclusion_keywords:
            for kw in exclusion_keywords:
                kw_clean = re.sub(r"[^\w\s]", "", kw).strip()
                if len(kw_clean) >= 3:
                    before_count = len(df)
                    df = df[~df[COL_DISH].str.lower().str.contains(r"\b" + re.escape(kw_clean) + r"\b", regex=True, na=False)]
                    removed = before_count - len(df)
                    if removed > 0:
                        allergy_warnings.append(f"Excluded {removed} dish(es) containing '{kw_clean}' in title.")

        # -------------------------------------------------------------
        # 5. Evaluate and Rank ALL Suitable Records Using Actual Nutrients
        # -------------------------------------------------------------
        scored_records = []
        for _, row in df.iterrows():
            dish_dict = self.dm.row_to_dict(row)
            score, explanation, highlights, limitations = self._evaluate_dish(
                dish=dish_dict,
                diabetes_type=diabetes_type,
                current_glucose=current_glucose,
                reading_type=reading_type,
                target_glucose=target_glucose,
                dietary_preferences=diet_pref,
                health_focus=health_focus,
                bmi=bmi,
                bmi_category=bmi_category
            )
            dish_dict["recommendation_score"] = score
            dish_dict["recommendation_reason"] = explanation
            dish_dict["highlights"] = highlights
            dish_dict["limitations"] = limitations
            dish_dict["glycemic_impact"] = self._compute_glycemic_impact(dish_dict)
            scored_records.append(dish_dict)

        # Sort descending by personalized recommendation score
        scored_records.sort(key=lambda x: x["recommendation_score"], reverse=True)

        # Build Daily Meal Plan (Breakfast, Lunch, Snack, Dinner) from top ranked items
        daily_plan = {
            "breakfast": [],
            "lunch": [],
            "snack": [],
            "dinner": []
        }
        for item in scored_records:
            cat_lower = item.get("category", "").lower()
            if "breakfast" in cat_lower and len(daily_plan["breakfast"]) < 3:
                daily_plan["breakfast"].append(item)
            elif "snack" in cat_lower and len(daily_plan["snack"]) < 3:
                daily_plan["snack"].append(item)
            elif ("lunch" in cat_lower or "dinner" in cat_lower):
                if len(daily_plan["lunch"]) < 3:
                    daily_plan["lunch"].append(item)
                elif len(daily_plan["dinner"]) < 3:
                    daily_plan["dinner"].append(item)

        # Category filter for list view if specified
        if category_filter and category_filter.strip().lower() != "all":
            target_cat = category_filter.strip().lower()
            filtered_scored = [
                d for d in scored_records 
                if target_cat in d.get("category", "").lower()
            ]
            top_records = filtered_scored[:limit] if filtered_scored else scored_records[:limit]
        else:
            top_records = scored_records[:limit]

        return {
            "patient_context": {
                "diabetes_type": diabetes_type,
                "current_glucose_mg_dl": current_glucose,
                "target_glucose_mg_dl": target_glucose,
                "reading_type": reading_type,
                "reading_date": reading_date,
                "glucose_status": glucose_status,
                "glucose_context_note": glucose_context_note,
                "height_cm": height_cm,
                "weight_kg": weight_kg,
                "bmi": bmi,
                "bmi_category": bmi_category,
                "bmi_context_note": bmi_context_note,
                "age": age,
                "dietary_preference": diet_pref,
                "allergies": allergies,
                "health_focus": health_focus,
                "activity_level": activity_level,
                "category_filter": category_filter or "All"
            },
            "hypoglycemia_alert": hypo_alert,
            "allergy_filtering_notes": allergy_warnings,
            "recommendations_count": len(top_records),
            "recommendations": top_records,
            "daily_plan": daily_plan,
            "disclaimer": (
                "Medical Disclaimer: These recommendations are informational wellness suggestions ranked from your food dataset "
                "based on reported nutritional values and personal preferences. They do not constitute a medical diagnosis or diet prescription. "
                "Because ingredient formulations vary by preparation, please verify full ingredients with recipe creators or your clinical dietitian."
            )
        }

    def _evaluate_dish(
        self,
        dish: Dict[str, Any],
        diabetes_type: str,
        current_glucose: Optional[float],
        reading_type: Optional[str],
        target_glucose: float,
        dietary_preferences: str,
        health_focus: str,
        bmi: Optional[float],
        bmi_category: Optional[str]
    ) -> Tuple[int, str, List[str], List[str]]:
        """
        Evaluates a single dish using actual dataset nutrient values.
        Returns: (score, simple_explanation, highlights, limitations).
        """
        score = 70.0
        highlights = []
        limitations = []

        carbs = dish.get("carbs_g")
        sugar = dish.get("free_sugar_g")
        fibre = dish.get("fibre_g")
        protein = dish.get("protein_g")
        fat = dish.get("fat_g")
        calories = dish.get("calories")
        sodium = dish.get("sodium_mg")

        # -------------------------------------------------------------
        # Rule 1: Free Sugar (Highest glycemic surge factor)
        # -------------------------------------------------------------
        sugar_phrase = "unspecified free sugar"
        if sugar is not None:
            if sugar == 0.0:
                score += 14
                sugar_phrase = "zero free sugar (0g) to avoid glucose spikes"
                highlights.append("0g Free Sugar")
            elif sugar <= 2.0:
                score += 8
                sugar_phrase = f"a low free-sugar value ({sugar}g)"
                highlights.append(f"{sugar}g Sugar")
            elif sugar <= 5.0:
                score -= 4
                sugar_phrase = f"moderate free sugar ({sugar}g)"
            else:
                penalty = min(25, 10 + int(sugar * 2))
                score -= penalty
                sugar_phrase = f"a higher free-sugar value ({sugar}g)"
        else:
            limitations.append("Free sugar content is not recorded in the source dataset.")

        # -------------------------------------------------------------
        # Rule 2: Dietary Fibre (Slows absorption, flattens glucose curve)
        # -------------------------------------------------------------
        fibre_phrase = "minimal fibre"
        if fibre is not None:
            if fibre >= 5.0:
                score += 15
                fibre_phrase = f"substantial dietary fibre ({fibre}g) to blunt glycemic absorption"
                highlights.append(f"{fibre}g Fibre")
            elif fibre >= 2.5:
                score += 8
                fibre_phrase = f"helpful dietary fibre ({fibre}g)"
                highlights.append(f"{fibre}g Fibre")
            elif fibre >= 1.0:
                fibre_phrase = f"mild dietary fibre ({fibre}g)"
            else:
                score -= 6
                fibre_phrase = "low dietary fibre (<1g)"
        else:
            limitations.append("Dietary fibre value is unspecified in the dataset.")

        # -------------------------------------------------------------
        # Rule 3: Carbohydrate Load
        # -------------------------------------------------------------
        carb_phrase = "unspecified carbs"
        if carbs is not None:
            if carbs <= 12.0:
                score += 12
                carb_phrase = f"a very low carbohydrate load ({carbs}g)"
                highlights.append(f"{carbs}g Carbs")
            elif carbs <= 25.0:
                score += 6
                carb_phrase = f"manageable carbohydrates ({carbs}g)"
            elif carbs <= 45.0:
                score -= 4
                carb_phrase = f"moderate-to-high carbohydrates ({carbs}g)"
            else:
                penalty = min(22, 8 + int((carbs - 45) * 0.4))
                score -= penalty
                carb_phrase = f"a high carbohydrate load ({carbs}g)"
        else:
            limitations.append("Carbohydrate value is not specified in the dataset.")

        # -------------------------------------------------------------
        # Rule 4: Protein Support (Promotes steady satiety)
        # -------------------------------------------------------------
        if protein is not None:
            if protein >= 15.0:
                score += 12
                highlights.append(f"{protein}g Protein")
            elif protein >= 7.0:
                score += 6
                highlights.append(f"{protein}g Protein")

        # -------------------------------------------------------------
        # Rule 5: Fats & Calorie Density
        # -------------------------------------------------------------
        if fat is not None:
            if fat > 22.0:
                score -= 8
            elif 3.0 <= fat <= 12.0:
                score += 4

        if calories is not None:
            if calories > 450:
                score -= 8
            elif calories <= 250:
                score += 4

        # -------------------------------------------------------------
        # Rule 6: Sodium Moderation (Cardiovascular safety)
        # -------------------------------------------------------------
        if sodium is not None:
            if sodium <= 140:
                score += 5
                highlights.append("Low Sodium")
            elif sodium > 600:
                score -= 8

        # -------------------------------------------------------------
        # Rule 7: Glucose-Aware Nutritional Adjustments
        # -------------------------------------------------------------
        if current_glucose is not None:
            g_val = float(current_glucose)
            if g_val >= 180.0:
                # Elevated blood sugar: strictly reward low carbs and low sugar
                if carbs is not None:
                    if carbs <= 10.0:
                        score += 18
                        highlights.append("Glycemic Stabilizer")
                    elif carbs > 20.0:
                        score -= 20
                if sugar is not None and sugar > 1.5:
                    score -= 22
                if fibre is not None and fibre >= 3.0:
                    score += 12
            elif g_val >= 140.0:
                # Mildly elevated blood sugar
                if carbs is not None and carbs <= 15.0:
                    score += 10
                    highlights.append("Gentle Carbs")
                if sugar is not None and sugar > 3.0:
                    score -= 12

        # -------------------------------------------------------------
        # Rule 8: Multi-Select Dietary Preferences Tailoring
        # -------------------------------------------------------------
        diet_lower = dietary_preferences.lower()
        if "low-carb" in diet_lower or "keto" in diet_lower:
            if carbs is not None and carbs <= 12.0:
                score += 14
                highlights.append("Low-Carb Match")
            elif carbs is not None and carbs > 30.0:
                score -= 14

        # -------------------------------------------------------------
        # Rule 9: Multi-Select Health Focus Area Adjustments
        # -------------------------------------------------------------
        focus_lower = health_focus.lower()
        if "glucose" in focus_lower or "control" in focus_lower:
            if sugar is not None and sugar == 0.0:
                score += 10
                highlights.append("Spike Protector")
            if fibre is not None and fibre >= 4.0:
                score += 8

        if "weight" in focus_lower or (bmi_category in ["Overweight", "Obese"]):
            if calories is not None and calories <= 220:
                score += 8
                highlights.append("Calorie-Light")
            elif calories is not None and calories > 360:
                score -= 8

        if "heart" in focus_lower or "cardio" in focus_lower:
            if sodium is not None and sodium <= 140:
                score += 8
                highlights.append("Heart-Friendly")
            elif sodium is not None and sodium > 500:
                score -= 8

        if "active" in focus_lower or "energy" in focus_lower or "fitness" in focus_lower:
            if protein is not None and protein >= 12.0:
                score += 10
                highlights.append("Muscle Recovery")

        if "immunity" in focus_lower or "wellness" in focus_lower:
            vit_c = dish.get("vitamin_c_mg")
            if vit_c is not None and vit_c >= 5.0:
                score += 8
                highlights.append("Vitamin C Rich")

        # -------------------------------------------------------------
        # Rule 10: Allergy & Unknown Ingredient Disclaimers
        # -------------------------------------------------------------
        limitations.append(
            "Ingredient formulations vary by preparation; please verify full ingredients with recipe creator or clinician."
        )
        if dish.get("has_missing_nutrients"):
            missing_cols = []
            if dish.get("vitamin_c_mg") is None:
                missing_cols.append("Vitamin C")
            if dish.get("folate_ug") is None:
                missing_cols.append("Folate")
            if missing_cols:
                limitations.append(f"Micronutrient values for {', '.join(missing_cols)} are not reported in the source dataset.")

        # -------------------------------------------------------------
        # Build Standardized Simple Explanation
        # -------------------------------------------------------------
        explanation = (
            f"Recommended because this food has {sugar_phrase}, {carb_phrase}, "
            f"and provides {fibre_phrase} compared with other available dataset options."
        )

        final_score = int(max(15, min(99, round(score))))
        return final_score, explanation, highlights[:3], limitations

    @staticmethod
    def _compute_glycemic_impact(dish: Dict[str, Any]) -> Dict[str, Any]:
        """Calculates glycemic impact badge and description from actual dataset macronutrients."""
        carbs = dish.get("carbs_g") or 0.0
        sugar = dish.get("free_sugar_g") or 0.0
        fibre = dish.get("fibre_g") or 0.0

        net_carbs = max(0.0, carbs - fibre)

        if net_carbs <= 12.0 and sugar <= 2.0:
            return {
                "level": "Low",
                "badge": "Low Glycemic Impact",
                "color": "#2E7D32",
                "bg_color": "#E8F5E9",
                "description": "Gradual glucose absorption with minimal post-meal spike."
            }
        elif net_carbs <= 30.0 and sugar <= 6.0:
            return {
                "level": "Moderate",
                "badge": "Moderate Glycemic Impact",
                "color": "#E65100",
                "bg_color": "#FFF3E0",
                "description": "Balanced glycemic response; ensure recommended portion size."
            }
        else:
            return {
                "level": "High",
                "badge": "High Glycemic Impact",
                "color": "#C62828",
                "bg_color": "#FFEBEE",
                "description": "Higher carbohydrate load; monitor post-prandial blood sugar."
            }

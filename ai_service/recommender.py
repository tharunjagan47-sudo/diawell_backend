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

MEAT_FISH_KEYWORDS = [
    "chicken", "mutton", "fish", "prawn", "keema", "gosht", "meat",
    "crab", "pork", "beef", "shrimp", "duck", "pomfret", "salmon",
    "tuna", "seafood", "tikka (chicken)", "ham", "bacon", "lamb"
]

EGG_KEYWORDS = [
    "egg", "anda", "omlet", "omelette", "bhurji", "poached egg", "boiled egg", "fried egg"
]

ALL_NON_VEG_KEYWORDS = MEAT_FISH_KEYWORDS + EGG_KEYWORDS

DAIRY_KEYWORDS = [
    "paneer", "milk", "curd", "dahi", "cheese", "butter", "ghee",
    "malai", "lassi", "raita", "khoya", "cream", "kheer", "rabri"
]

BEVERAGE_KEYWORDS = [
    "tea", "chai", "coffee", "cooler", "juice", "drink", "shake",
    "smoothie", "water", "punch", "lemonade", "cocoa", "sharbat",
    "squash", "soda", "lassi", "lem-o-gin", "cordial", "syrup"
]

BROTH_KEYWORDS = [
    "brown stock", "vegetable stock", "mutton stock", "chicken stock", "beef stock", "meat stock"
]

BREAKFAST_KEYWORDS = [
    "oats", "idli", "dosa", "poha", "upma", "paratha", "parantha", "pancake",
    "toast", "cornflakes", "porridge", "thepla", "cheela", "chilla", "puri", "poori",
    "sandwich", "daliya", "omelet", "omlet", "bhurji", "dhokla", "egg sandwich"
]

SNACK_KEYWORDS = [
    "soup", "chaat", "tikki", "pakoda", "pakora", "samosa", "cutlet",
    "biscuit", "cookie", "roll", "kebab", "vada", "bhel", "chivda",
    "salad", "raita", "dip", "chutney", "murukku", "snack"
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
        for kw in ALL_NON_VEG_KEYWORDS:
            if re.search(r"\b" + re.escape(kw) + r"\b", name_lower):
                return "Non-Vegetarian"
        return "Vegetarian"

    @staticmethod
    def _infer_category(name: str) -> str:
        name_lower = name.lower()
        for kw in BROTH_KEYWORDS:
            if kw in name_lower:
                return "Broth"
        for kw in BEVERAGE_KEYWORDS:
            if kw in name_lower:
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
            "portion": "100 g portion basis",
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

        diet_pref = str(patient_profile.get("dietary_preferences") or patient_profile.get("preferred_diet") or "Vegetarian").strip()
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
        # 1. BMI Calculation for Contextual Health Information
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
        # -------------------------------------------------------------
        glucose_context_note = None
        glucose_status = "normal"
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
                    if g_val >= 126.0:
                        glucose_status = "high"
                    elif g_val >= 100.0:
                        glucose_status = "elevated"
                    else:
                        glucose_status = "normal"
                elif "post" in r_type_clean.lower() or "meal" in r_type_clean.lower():
                    if g_val >= 200.0:
                        glucose_status = "high"
                    elif g_val >= 140.0:
                        glucose_status = "elevated"
                    else:
                        glucose_status = "normal"
                else:
                    if g_val >= 180.0:
                        glucose_status = "high"
                    elif g_val >= 140.0:
                        glucose_status = "elevated"
                    else:
                        glucose_status = "normal"

                glucose_context_note = (
                    f"{r_type_clean} blood glucose reading of {g_val:.0f} mg/dL{date_str} evaluated as a contextual indicator."
                )
            except (ValueError, TypeError):
                current_glucose = None

        # -------------------------------------------------------------
        # 3. Dietary Preference Filters (supports multi-select)
        # -------------------------------------------------------------
        diet_lower = diet_pref.lower()
        is_vegan = "vegan" in diet_lower
        is_eggetarian = "eggetarian" in diet_lower
        is_veg_only = ("veg" in diet_lower and "non" not in diet_lower and not is_eggetarian)
        is_low_carb = "low-carb" in diet_lower or "keto" in diet_lower

        if is_vegan:
            df = df[df["diet_type"] == "Vegetarian"]
            dairy_pattern = "|".join([r"\b" + re.escape(k) + r"\b" for k in DAIRY_KEYWORDS])
            df = df[~df[COL_DISH].str.lower().str.contains(dairy_pattern, regex=True, na=False)]
        elif is_eggetarian:
            meat_pattern = "|".join([r"\b" + re.escape(k) + r"\b" for k in MEAT_FISH_KEYWORDS])
            df = df[~df[COL_DISH].str.lower().str.contains(meat_pattern, regex=True, na=False)]
        elif is_veg_only:
            df = df[df["diet_type"] == "Vegetarian"]

        # -------------------------------------------------------------
        # 4. Allergy / Avoidance Exclusion
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
        # 5. Distinguish Real Meals from Beverages and Broths
        # (Beverages/broths never crowd out real meals in main view)
        # -------------------------------------------------------------
        cat_lower = (category_filter or "").strip().lower()
        if cat_lower == "beverage":
            df_food = df[df["category"] == "Beverage"]
        else:
            df_food = df[~df["category"].isin(["Broth", "Beverage"])]
            if cat_lower != "" and cat_lower != "all":
                df_food = df_food[df_food["category"].str.lower().str.contains(cat_lower, na=False)]

        # -------------------------------------------------------------
        # 6. Evaluate and Rank Suitable Records Using Clinical Nutrients
        # -------------------------------------------------------------
        scored_records = []
        for _, row in df_food.iterrows():
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

        # -------------------------------------------------------------
        # 7. Build Real Daily Meal Plan (Breakfast, Lunch, Snack, Dinner)
        # -------------------------------------------------------------
        daily_plan = {
            "breakfast": [],
            "lunch": [],
            "snack": [],
            "dinner": []
        }

        # Candidate pool from non-beverage dishes evaluated
        for item in scored_records:
            item_cat = item.get("category", "")
            if item_cat == "Breakfast" and len(daily_plan["breakfast"]) < 2:
                daily_plan["breakfast"].append(item)
            elif item_cat == "Lunch/Dinner":
                if len(daily_plan["lunch"]) < 2:
                    daily_plan["lunch"].append(item)
                elif len(daily_plan["dinner"]) < 2:
                    daily_plan["dinner"].append(item)
            elif item_cat == "Snack" and len(daily_plan["snack"]) < 1:
                daily_plan["snack"].append(item)

        # Ensure fallback for breakfast/snack if specific categories had few items in filtered sub-pool
        if len(daily_plan["breakfast"]) < 2 or len(daily_plan["snack"]) < 1 or len(daily_plan["dinner"]) < 2:
            all_non_bev = [d for d in scored_records if d.get("category") not in ["Beverage", "Broth"]]
            for item in all_non_bev:
                if len(daily_plan["breakfast"]) < 2 and item not in daily_plan["breakfast"] and item not in daily_plan["lunch"]:
                    daily_plan["breakfast"].append(item)
                elif len(daily_plan["dinner"]) < 2 and item not in daily_plan["lunch"] and item not in daily_plan["dinner"]:
                    daily_plan["dinner"].append(item)
                elif len(daily_plan["snack"]) < 1 and item not in daily_plan["lunch"] and item not in daily_plan["snack"]:
                    daily_plan["snack"].append(item)

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
                "Medical Disclaimer: Suggestions are calculated from your nutritional dataset based on diabetic guidelines. "
                "Always verify full ingredients with recipe creators or your clinical dietitian."
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
        Evaluates a single dish using actual dataset nutrient values and patient inputs.
        Returns: (score, explanation, highlights, limitations).
        """
        score = 70.0
        highlights = []
        limitations = []

        dish_name = str(dish.get("dish_name") or dish.get("title") or "")
        name_lower = dish_name.lower()

        carbs = dish.get("carbs_g")
        sugar = dish.get("free_sugar_g")
        fibre = dish.get("fibre_g")
        protein = dish.get("protein_g")
        fat = dish.get("fat_g")
        calories = dish.get("calories")
        sodium = dish.get("sodium_mg")

        g_val = float(current_glucose) if current_glucose is not None else 120.0
        is_hypo = g_val < 70.0
        is_high = g_val >= 180.0
        is_elevated = (g_val >= 140.0 and g_val < 180.0)
        is_normal = (g_val >= 70.0 and g_val < 140.0)

        diet_lower = dietary_preferences.lower()
        is_low_carb = ("low-carb" in diet_lower or "keto" in diet_lower)
        focus_lower = health_focus.lower()

        # -------------------------------------------------------------
        # 1. Glucose-Specific Clinical Response
        # -------------------------------------------------------------
        if is_hypo:
            # Hypoglycemia: need fast-acting carbohydrates to safely raise blood sugar
            if carbs is not None:
                if 12.0 <= carbs <= 28.0:
                    score += 25
                    highlights.append("Hypo Rescue Carbs")
                elif carbs < 5.0:
                    score -= 15
                elif carbs > 40.0:
                    score -= 10
            if sugar is not None and sugar >= 1.5:
                score += 15
                highlights.append("Fast Glucose")
        elif is_high:
            # Hyperglycemia (>= 180 mg/dL): strictly prioritize ultra-low carbs, 0g sugar, high fiber
            if carbs is not None:
                if carbs <= 8.0:
                    score += 25
                    highlights.append("Ultra-Low Carb")
                elif carbs <= 14.0:
                    score += 15
                    highlights.append("Low Carbs")
                elif carbs > 24.0:
                    score -= 30
            if sugar is not None:
                if sugar == 0.0:
                    score += 18
                    highlights.append("0g Added Sugar")
                elif sugar > 1.0:
                    score -= 25
            if fibre is not None and fibre >= 3.0:
                score += 16
                highlights.append(f"{fibre}g Fibre")
            if protein is not None and protein >= 8.0:
                score += 12
                highlights.append(f"{protein}g Protein")
            # Strongly penalize high glycemic potato / aloo and deep-fried breads
            if "aloo" in name_lower or "potato" in name_lower:
                score -= 35
            if any(f in name_lower for f in ["poori", "puri", "bhatura", "kachori", "pakoda", "pakora"]):
                score -= 20
        elif is_elevated:
            # Mildly elevated (140 - 179 mg/dL)
            if carbs is not None:
                if carbs <= 15.0:
                    score += 16
                    highlights.append("Gentle Carbs")
                elif carbs > 30.0:
                    score -= 18
            if sugar is not None and sugar == 0.0:
                score += 12
                highlights.append("Zero Sugar")
            if fibre is not None and fibre >= 3.0:
                score += 12
                highlights.append(f"{fibre}g Fibre")
            if protein is not None and protein >= 8.0:
                score += 8
            if "aloo" in name_lower or "potato" in name_lower:
                score -= 20
        else:
            # Normal range (70 - 139 mg/dL)
            if carbs is not None:
                if carbs <= 20.0:
                    score += 12
                    highlights.append(f"{carbs}g Carbs")
                elif carbs > 40.0:
                    score -= 15
            if sugar is not None:
                if sugar == 0.0:
                    score += 12
                    highlights.append("0g Sugar")
                elif sugar > 3.0:
                    score -= 14
            if fibre is not None and fibre >= 3.0:
                score += 12
                highlights.append(f"{fibre}g Fibre")
            if protein is not None and protein >= 7.0:
                score += 10
                highlights.append(f"{protein}g Protein")

        # -------------------------------------------------------------
        # 2. Anti-Diabetic Ingredient Recognition
        # -------------------------------------------------------------
        if any(k in name_lower for k in ["karela", "bitter gourd"]):
            score += 20
            highlights.append("Karela Anti-Spike")
        elif any(k in name_lower for k in ["methi", "fenugreek"]):
            score += 16
            highlights.append("Methi Glucose Shield")
        elif any(k in name_lower for k in ["palak", "spinach", "saag"]):
            score += 14
            highlights.append("Greens Fiber")
        elif any(k in name_lower for k in ["bhindi", "okra"]):
            score += 14
            highlights.append("Soluble Fiber")
        elif any(k in name_lower for k in ["moong", "black bean", "chana", "dal"]):
            score += 12
            highlights.append("Complex Legume")
        elif "paneer" in name_lower:
            score += 10
            highlights.append("Protein Rich")

        # -------------------------------------------------------------
        # 3. Low-Carb / Keto Tailoring
        # -------------------------------------------------------------
        if is_low_carb:
            if carbs is not None:
                if carbs <= 10.0:
                    score += 18
                    highlights.append("Keto Compliant")
                elif carbs > 22.0:
                    score -= 22

        # -------------------------------------------------------------
        # 4. Multi-Select Health Focus Adjustments
        # -------------------------------------------------------------
        if "weight" in focus_lower or (bmi_category in ["Overweight", "Obese"]):
            if calories is not None:
                if calories <= 200:
                    score += 12
                    highlights.append("Calorie-Light")
                elif calories > 340:
                    score -= 15
            if protein is not None and protein >= 10.0:
                score += 10
                highlights.append("Satiating Protein")

        if "heart" in focus_lower or "cardio" in focus_lower:
            if sodium is not None:
                if sodium <= 140:
                    score += 14
                    highlights.append("Heart-Friendly")
                elif sodium > 450:
                    score -= 18

        if "active" in focus_lower or "fitness" in focus_lower or "energy" in focus_lower:
            if protein is not None and protein >= 12.0:
                score += 14
                highlights.append("Muscle Recovery")

        if "immunity" in focus_lower or "wellness" in focus_lower:
            vit_c = dish.get("vitamin_c_mg")
            if vit_c is not None and vit_c >= 5.0:
                score += 10
                highlights.append("Vitamin C Rich")

        # -------------------------------------------------------------
        # 5. Build Dynamic Personalized Explanation
        # -------------------------------------------------------------
        carb_str = f"{carbs}g" if carbs is not None else "minimal"
        fibre_str = f"{fibre}g" if fibre is not None else "digestive"
        sugar_str = f"{sugar}g" if sugar is not None else "0g"

        if is_hypo:
            explanation = f"Recommended for low blood glucose ({g_val:.0f} mg/dL): provides gentle fast carbohydrates ({carb_str}) to safely assist recovery."
        elif is_high:
            explanation = f"Recommended for high glucose ({g_val:.0f} mg/dL): provides ultra-low carbohydrates ({carb_str}), {sugar_str} free sugar, and {fibre_str} fibre to stabilize blood sugar."
        elif is_elevated:
            explanation = f"Recommended for mildly elevated glucose ({g_val:.0f} mg/dL): balanced low-glycemic meal with {carb_str} carbs and {fibre_str} fibre."
        else:
            explanation = f"Recommended for your diabetes targets: provides {carb_str} carbs, {sugar_str} sugar, and {fibre_str} fibre to maintain steady energy."

        limitations.append("Formulation varies by preparation; verify full ingredients with recipe creator or clinician.")

        final_score = int(max(20, min(99, round(score))))
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

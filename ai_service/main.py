"""
DiaWell AI Food Recommendation & Dataset Explorer Service
FastAPI-powered microservice integrating Indian Food Nutrition Dataset (1000+ dishes)
"""

import os
from typing import List, Optional, Any, Dict
from urllib.parse import unquote
from fastapi import FastAPI, Query, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from recommender import NutritionDatasetManager, DiabetesRecommendationEngine

# Path to the dataset
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATASET_PATH = os.path.join(BASE_DIR, "data", "Indian_Food_Nutrition_Processed.csv")

# Initialize dataset manager and recommendation engine
dataset_manager = NutritionDatasetManager(DATASET_PATH)
recommender_engine = DiabetesRecommendationEngine(dataset_manager)

app = FastAPI(
    title="DiaWell AI Nutrition & Food Recommendation Service",
    description="Evidence-informed clinical nutrition recommendations & search for diabetes management.",
    version="1.1.0"
)

# Enable CORS for local development, emulator, and PHP proxy
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# =========================================================================
# PYDANTIC REQUEST & RESPONSE MODELS
# =========================================================================

class PatientProfileRequest(BaseModel):
    user_id: Optional[int] = None
    diabetes_type: Optional[str] = Field(default="Type 2", description="Type 1, Type 2, Gestational, Pre-diabetes")
    current_glucose_mg_dl: Optional[float] = Field(default=None, description="Recent blood glucose in mg/dL")
    target_glucose_mg_dl: Optional[float] = Field(default=120.0, description="Target fasting or pre-meal blood glucose")
    reading_type: Optional[str] = Field(default=None, description="Fasting, Post-prandial, Random, Bedtime, etc.")
    reading_date: Optional[str] = Field(default=None, description="Date of blood glucose reading (YYYY-MM-DD)")
    height_cm: Optional[float] = Field(default=None, description="Patient height in cm")
    weight_kg: Optional[float] = Field(default=None, description="Patient weight in kg")
    age: Optional[int] = Field(default=None, description="Patient age")
    dietary_preferences: Optional[str] = Field(default="Non-Vegetarian", description="Vegetarian, Non-Vegetarian, Vegan")
    allergies: Optional[List[str]] = Field(default_factory=list, description="List of patient allergens")
    foods_to_avoid: Optional[List[str]] = Field(default_factory=list, description="List of foods patient wishes to avoid")
    health_focus: Optional[str] = Field(default="Glucose Control", description="Health focus area")
    activity_level: Optional[str] = Field(default="Moderate", description="Sedentary, Light, Moderate, Active")
    category: Optional[str] = Field(default=None, description="Meal category filter: Breakfast, Lunch, Snack, Dinner, or All")
    limit: Optional[int] = Field(default=10, ge=1, le=50, description="Number of recommendations to return")


# =========================================================================
# API ENDPOINTS
# =========================================================================

@app.get("/health", tags=["System"])
def health_check():
    """Health status and dataset loading check."""
    stats = dataset_manager.get_stats()
    return {
        "status": "healthy",
        "service": "DiaWell AI Food Recommendation Service",
        "dataset_loaded": dataset_manager.df is not None,
        "total_dishes": stats.get("total_dishes", 0),
        "dataset_path": DATASET_PATH
    }


@app.get("/stats", tags=["Dataset"])
def dataset_stats():
    """Returns dataset summary statistics and column distributions."""
    return dataset_manager.get_stats()


@app.get("/categories", tags=["Dataset"])
def list_categories():
    """Returns available categories and diet types from the dataset."""
    if dataset_manager.df is None:
        return {"categories": [], "diet_types": []}
    return {
        "categories": sorted(list(dataset_manager.df["category"].dropna().unique())),
        "diet_types": sorted(list(dataset_manager.df["diet_type"].dropna().unique()))
    }


@app.post("/recommend", tags=["AI Recommendations"])
def get_recommendations(profile: PatientProfileRequest):
    """
    Ranks suitable foods from the 1000+ Indian Food Dataset based on patient's health parameters.
    Evaluates ALL candidate foods using nutritional values (no exact search filter).
    Includes BMI context, glucose reading context, transparent scores, explanations, and allergen limitations.
    """
    try:
        results = recommender_engine.recommend_for_patient(
            patient_profile=profile.dict(),
            limit=profile.limit or 10,
            category=profile.category
        )
        return {
            "success": True,
            "message": f"Successfully generated {results.get('recommendations_count', 0)} personalized recommendations.",
            "data": results
        }
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error evaluating food recommendations: {str(e)}"
        )


@app.get("/search", tags=["Food Explorer"])
def search_food(
    query: Optional[str] = Query(None, description="Partial or full dish name"),
    category: Optional[str] = Query(None, description="Category filter (e.g. Breakfast, Lunch, Snack, Dinner, or All)"),
    diet_type: Optional[str] = Query(None, description="Diet filter (Vegetarian, Non-Vegetarian)"),
    max_carbs: Optional[float] = Query(None, description="Maximum carbohydrates (g)"),
    min_protein: Optional[float] = Query(None, description="Minimum protein (g)"),
    max_sugar: Optional[float] = Query(None, description="Maximum free sugar (g)"),
    max_calories: Optional[float] = Query(None, description="Maximum calories (kcal)"),
    sort_by: str = Query("name", description="name, calories, carbs, protein, fibre, sugar, sodium"),
    sort_order: str = Query("asc", description="asc or desc"),
    page: int = Query(1, ge=1, description="Page number"),
    limit: int = Query(30, ge=1, le=100, description="Items per page")
):
    """
    Case-insensitive partial match search across the entire 1000+ Indian Food Dataset with pagination.
    """
    try:
        search_result = dataset_manager.search_dishes(
            query=query,
            category=category,
            diet_type=diet_type,
            max_carbs=max_carbs,
            min_protein=min_protein,
            max_sugar=max_sugar,
            max_calories=max_calories,
            sort_by=sort_by,
            sort_order=sort_order,
            page=page,
            limit=limit
        )
        return {
            "success": True,
            "message": f"Found {search_result['total']} matching food items.",
            "data": search_result
        }
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error searching food dataset: {str(e)}"
        )


@app.get("/food/id/{item_id}", tags=["Food Explorer"])
def get_food_details_by_id(item_id: int):
    """
    Retrieves full nutritional facts and diabetic impact evaluation for a specific dish by item ID.
    """
    food = dataset_manager.get_by_id(item_id)
    if not food:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Dish with item ID {item_id} not found in dataset."
        )

    glycemic = DiabetesRecommendationEngine._compute_glycemic_impact(food)
    return {
        "success": True,
        "message": "Dish details retrieved successfully.",
        "data": {
            **food,
            "glycemic_details": glycemic
        }
    }


@app.get("/food/{dish_name}", tags=["Food Explorer"])
def get_food_details(dish_name: str):
    """
    Retrieves full nutritional facts and diabetic impact evaluation for a specific dish by name or numeric ID string.
    """
    decoded_name = unquote(dish_name).strip()
    
    # If a numeric ID was passed in the name path, resolve via ID
    if decoded_name.isdigit():
        return get_food_details_by_id(int(decoded_name))

    food = dataset_manager.get_by_name(decoded_name)
    if not food:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Dish '{decoded_name}' not found in the dataset."
        )

    glycemic = DiabetesRecommendationEngine._compute_glycemic_impact(food)
    return {
        "success": True,
        "message": "Dish details retrieved successfully.",
        "data": {
            **food,
            "glycemic_details": glycemic
        }
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="127.0.0.1", port=8001, reload=True)

"""

DiaWell AI Health & Nutrition Microservice Gateway

Target Architecture: Diawell Client (Mobile/Web) ---> FastAPI Gateway (Port 8000) ---> Local Ollama (Port 11434 / qwen2.5:3b)



Features:

- High-performance asynchronous FastAPI server running on Uvicorn.

- Native integration with local Ollama engine via official `ollama.AsyncClient`.

- Full Server-Sent Events (SSE) streaming for real-time word-by-word token generation.

- Strict Pydantic v2 schemas for chat requests and contextual history validation.

- Specialized Diawell Clinical Persona and System Prompt for diabetic care.

- Unified health check verifying gateway status and Ollama engine connectivity.

- Retains dataset-backed nutrition and physical activity recommendation engines.

"""



import os
os.environ["CUDA_VISIBLE_DEVICES"] = "-1"
os.environ["OLLAMA_NUM_GPU"] = "0"

import re

import json

import time

from enum import Enum

from typing import List, Optional, Dict, Any, AsyncGenerator

from urllib.parse import unquote



from fastapi import FastAPI, HTTPException, status, Query, Request

from fastapi.middleware.cors import CORSMiddleware

from fastapi.responses import StreamingResponse, JSONResponse

from pydantic import BaseModel, Field



import httpx

import ollama

from ollama import AsyncClient



from recommender import NutritionDatasetManager, DiabetesRecommendationEngine

from activity_recommender import CompendiumDatasetManager, ActivityRecommendationEngine



# =============================================================================

# ENVIRONMENT & SERVICE CONFIGURATION

# =============================================================================



BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

DATASET_PATH = os.path.join(BASE_DIR, "data", "Indian_Food_Nutrition_Processed.csv")

ACTIVITY_DATASET_PATH = os.path.join(BASE_DIR, "activity dataset", "walking_running_cycling.csv")



OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://localhost:11434")

DEFAULT_OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "qwen2.5:3b")



# Initialize dataset managers and recommendation engines

dataset_manager = NutritionDatasetManager(DATASET_PATH)

recommender_engine = DiabetesRecommendationEngine(dataset_manager)



compendium_manager = CompendiumDatasetManager(ACTIVITY_DATASET_PATH)

activity_engine = ActivityRecommendationEngine(compendium_manager)



# Initialize asynchronous Ollama client

ollama_client = AsyncClient(host=OLLAMA_HOST)



# =============================================================================

# CLINICAL PERSONA & DIAWELL SYSTEM PROMPT

# =============================================================================



DIAWELL_SYSTEM_PROMPT = (
    "You are Dr. Jarvis, DiaWell's rapid AI diabetes lifestyle & nutrition assistant. "
    "Provide short, direct, actionable guidance (maximum 2 to 3 bullet points or 2 concise sentences). "
    "Never generate reports, disclaimers, weekly summaries, or consultation notes at the end. "
    "For food queries, state dish name, carbs (g), and fibre (g). "
    "For exercise queries, provide MET value and estimated calories burned. "
    "For hypoglycemia (<70 mg/dL), recommend the Rule of 15 immediately."
)


# =============================================================================

# FASTAPI APPLICATION & CORS MIDDLEWARE

# =============================================================================



app = FastAPI(

    title="DiaWell AI Healthcare & LLM Gateway",

    description="High-performance async FastAPI service interfacing with local Ollama (qwen2.5:3b) and clinical diabetes datasets.",

    version="2.0.0"

)



# Explicit CORS configuration supporting Flutter, React, React Native, Android Emulators, and Web

app.add_middleware(

    CORSMiddleware,

    allow_origins=[

        "*",

        "http://localhost",

        "http://localhost:3000",

        "http://localhost:8080",

        "http://10.0.2.2",         # Android Emulator loopback

        "http://10.0.2.2:8000",

        "http://127.0.0.1",

        "http://127.0.0.1:3000",

        "http://127.0.0.1:8000",

    ],

    allow_credentials=True,

    allow_methods=["GET", "POST", "PUT", "DELETE", "OPTIONS"],

    allow_headers=["*"],

    expose_headers=["Content-Type", "X-Accel-Buffering"]

)



# =============================================================================

# PYDANTIC V2 SCHEMAS

# =============================================================================



class MessageRole(str, Enum):

    SYSTEM = "system"

    USER = "user"

    ASSISTANT = "assistant"





class ChatMessage(BaseModel):

    role: MessageRole = Field(..., description="Role of message author ('system', 'user', or 'assistant')")

    content: str = Field(..., min_length=1, description="Message body text")





class ChatRequest(BaseModel):

    prompt: Optional[str] = Field(

        default=None,

        description="The primary user query or message prompt."

    )

    message: Optional[str] = Field(

        default=None,

        description="Alternative key for user query (alias for prompt to support both payload formats)."

    )

    conversation_history: Optional[List[ChatMessage]] = Field(

        default=None,

        description="Optional preceding conversation turns for contextual continuity."

    )

    model: Optional[str] = Field(

        default=DEFAULT_OLLAMA_MODEL,

        description="Target Ollama model tag (defaults to qwen2.5:3b)."

    )

    temperature: Optional[float] = Field(

        default=0.7,

        ge=0.0,

        le=2.0,

        description="Sampling temperature for text diversity (0.0 to 2.0)."

    )

    user_id: Optional[int] = Field(

        default=None,

        description="Optional user ID for session context tracking."

    )
    options: Optional[Dict[str, Any]] = Field(
        default=None,
        description="Optional Ollama generation options dictionary."
    )
    keep_alive: Optional[str] = Field(
        default="30m",
        description="Keep alive duration for Ollama model."
    )



    def get_prompt(self) -> str:

        text = self.prompt or self.message

        if not text or not text.strip():

            raise ValueError("Either 'prompt' or 'message' must be provided.")

        return text.strip()





class ChatResponse(BaseModel):

    success: bool = True

    model: str

    response: str

    created_at: str

    duration_ms: Optional[float] = None





class LegacyChatRequest(BaseModel):

    message: str = Field(..., min_length=1, description="User message text")

    model: Optional[str] = Field(default=DEFAULT_OLLAMA_MODEL)

    user_id: Optional[int] = None





class PatientProfileRequest(BaseModel):

    user_id: Optional[int] = None

    diabetes_type: Optional[str] = Field(default="Type 2")

    current_glucose_mg_dl: Optional[float] = None

    target_glucose_mg_dl: Optional[float] = 120.0

    reading_type: Optional[str] = None

    reading_date: Optional[str] = None

    height_cm: Optional[float] = None

    weight_kg: Optional[float] = None

    age: Optional[int] = None

    dietary_preferences: Optional[str] = "Non-Vegetarian"

    allergies: Optional[List[str]] = Field(default_factory=list)

    foods_to_avoid: Optional[List[str]] = Field(default_factory=list)

    health_focus: Optional[str] = "Glucose Control"

    activity_level: Optional[str] = "Moderate"

    category: Optional[str] = None

    limit: Optional[int] = Field(default=10, ge=1, le=50)





class ActivityRecommendationRequest(BaseModel):

    user_id: Optional[int] = None

    age: Optional[int] = None

    height_cm: Optional[float] = None

    weight_kg: Optional[float] = None

    current_glucose_mg_dl: Optional[float] = None

    target_glucose_mg_dl: Optional[float] = 120.0

    reading_type: Optional[str] = None

    reading_date: Optional[str] = None

    reading_time: Optional[str] = None

    fitness_level: Optional[str] = "Moderate"

    preferred_activity: Optional[str] = "All"

    available_time_mins: Optional[int] = None

    physical_limitations: Optional[List[str]] = Field(default_factory=list)

    medications: Optional[List[str]] = Field(default_factory=list)

    recent_activities: Optional[List[Dict[str, Any]]] = Field(default_factory=list)



# =============================================================================

# HELPER FUNCTIONS

# =============================================================================



def build_ollama_messages(prompt: str, history: Optional[List[ChatMessage]] = None) -> List[Dict[str, str]]:

    """

    Constructs the formatted message list for Ollama, injecting the fixed

    DiaWell clinical persona as the initial system message.

    """

    messages: List[Dict[str, str]] = [

        {"role": "system", "content": DIAWELL_SYSTEM_PROMPT}

    ]



    # Append validated preceding dialogue turns if provided

    if history:

        for turn in history:

            # Exclude duplicate system prompts from client input

            if turn.role != MessageRole.SYSTEM:

                messages.append({"role": turn.role.value, "content": turn.content.strip()})



    # Append current user prompt

    messages.append({"role": "user", "content": prompt.strip()})

    return messages





async def generate_sse_stream(

    model: str,

    messages: List[Dict[str, str]],

    temperature: float = 0.7

) -> AsyncGenerator[str, None]:

    """

    Consumes the async Ollama chat stream and formats each chunk into

    a standard Server-Sent Events (SSE) data frame for the client.

    """

    try:

        stream = await ollama_client.chat(

            model=model,

            messages=messages,

            stream=True,

options={
                "temperature": temperature,
                "num_gpu": 0,        # CRITICAL: Force CPU-only execution to prevent CUDA PTX crash
                "num_ctx": 1024,     # Optimize context window for fast CPU RAM allocation
                "num_predict": 250,  # Cap output tokens to guarantee fast completion (<15s)
                "num_thread": 8      # Utilize CPU multithreading efficiently
            },
            keep_alive="30m"

        )



        async for chunk in stream:

            token = chunk.get("message", {}).get("content", "")

            if token:

                payload = json.dumps({"token": token, "done": False}, ensure_ascii=False)

                yield f"data: {payload}\n\n"



        # Signal completion

        completion_payload = json.dumps({"token": "", "done": True}, ensure_ascii=False)

        yield f"data: {completion_payload}\n\n"

        yield "data: [DONE]\n\n"



    except (ollama.ResponseError, httpx.ConnectError, ConnectionRefusedError) as conn_err:

        error_msg = f"Ollama connection error: {str(conn_err)}. Verify Ollama is running on {OLLAMA_HOST}."

        err_payload = json.dumps({"error": error_msg, "done": True}, ensure_ascii=False)

        yield f"data: {err_payload}\n\n"

        yield "data: [DONE]\n\n"

    except Exception as ex:

        err_payload = json.dumps({"error": f"Streaming failure: {str(ex)}", "done": True}, ensure_ascii=False)

        yield f"data: {err_payload}\n\n"

        yield "data: [DONE]\n\n"



# =============================================================================

# ENDPOINTS: SYSTEM HEALTH & OLLAMA DIAGNOSTICS

# =============================================================================



@app.get("/health", tags=["System"])

async def health_check():

    """

    Verifies FastAPI microservice status and inspects local Ollama engine connectivity,

    model availability, and dataset integrity.

    """

    ollama_online = False

    available_models: List[str] = []

    target_model_ready = False

    ollama_error: Optional[str] = None



    try:

        models_response = await ollama_client.list()

        raw_models = models_response.get("models", [])

        available_models = [

            m.get("model") or m.get("name") or str(m)

            for m in raw_models

        ]

        ollama_online = True

        target_model_ready = any(DEFAULT_OLLAMA_MODEL in m for m in available_models)

    except Exception as ex:

        ollama_error = str(ex)



    return {

        "status": "healthy",

        "gateway_port": 8000,

        "ollama_host": OLLAMA_HOST,

        "ollama_connected": ollama_online,

        "target_model": DEFAULT_OLLAMA_MODEL,

        "target_model_installed": target_model_ready,

        "available_models": available_models,

        "ollama_diagnostics": ollama_error,

        "dataset_loaded": dataset_manager.df is not None,

        "total_dishes": len(dataset_manager.df) if dataset_manager.df is not None else 0

    }



# =============================================================================

# ENDPOINTS: AI CHAT (JSON & REAL-TIME STREAMING)

# =============================================================================



@app.post("/api/v1/chat", response_model=ChatResponse, tags=["AI Chat"])

async def chat_json(request: ChatRequest):

    """

    Standard synchronous JSON response endpoint for non-streaming clients.

    Directly queries local Ollama (qwen2.5:3b) with DiaWell system persona.

    """

    start_time = time.time()

    try:

        user_prompt = request.get_prompt()

    except ValueError as ve:

        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(ve))



    target_model = request.model or DEFAULT_OLLAMA_MODEL

    messages = build_ollama_messages(prompt=user_prompt, history=request.conversation_history)



    try:

        response = await ollama_client.chat(

            model=target_model,

            messages=messages,

            stream=False,

options={
                "temperature": request.temperature if request.temperature is not None else 0.5,
                "num_gpu": 0,        # CRITICAL: Force CPU-only execution to prevent CUDA PTX crash
                "num_ctx": 1024,     # Optimize context window for fast CPU RAM allocation
                "num_predict": 250,  # Cap output tokens to guarantee fast completion (<15s)
                "num_thread": 8      # Utilize CPU multithreading efficiently
            },
            keep_alive="30m"

        )



        reply_content = response.get("message", {}).get("content", "").strip()

        elapsed_ms = round((time.time() - start_time) * 1000, 2)



        return ChatResponse(

            success=True,

            model=target_model,

            response=reply_content,

            created_at=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),

            duration_ms=elapsed_ms

        )



    except (ollama.ResponseError, httpx.ConnectError, ConnectionRefusedError) as conn_err:

        raise HTTPException(

            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,

            detail=(

                f"Cannot connect to local Ollama engine at {OLLAMA_HOST}. "

                f"Please ensure Ollama is running and '{target_model}' has been pulled. "

                f"Error: {str(conn_err)}"

            )

        )

    except Exception as ex:

        raise HTTPException(

            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,

            detail=f"AI inference error: {str(ex)}"

        )





@app.post("/api/v1/chat/stream", tags=["AI Chat"])

async def chat_stream(request: ChatRequest):

    """

    Server-Sent Events (SSE) StreamingResponse endpoint for real-time token rendering.

    Enables low-latency, word-by-word typing effect in Flutter, React, and mobile apps.

    """

    try:

        user_prompt = request.get_prompt()

    except ValueError as ve:

        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(ve))



    target_model = request.model or DEFAULT_OLLAMA_MODEL

    messages = build_ollama_messages(prompt=user_prompt, history=request.conversation_history)



    return StreamingResponse(

        generate_sse_stream(

            model=target_model,

            messages=messages,

            temperature=request.temperature or 0.7

        ),

        media_type="text/event-stream",

        headers={

            "Cache-Control": "no-cache, no-transform",

            "Connection": "keep-alive",

            "X-Accel-Buffering": "no",

            "Content-Type": "text/event-stream; charset=utf-8"

        }

    )





@app.post("/chat", tags=["Legacy Compatibility"])

async def legacy_chat_bridge(request: LegacyChatRequest):

    """

    Backward-compatibility route accepting legacy PHP/Postman payload format:

    { "message": "...", "model": "..." }

    """

    chat_req = ChatRequest(

        prompt=request.message,

        model=request.model or DEFAULT_OLLAMA_MODEL,

        user_id=request.user_id

    )

    result = await chat_json(chat_req)

    return {

        "success": True,

        "message": "AI response generated successfully.",

        "response": result.response,

        "model": result.model

    }



# =============================================================================

# ENDPOINTS: DIABETIC NUTRITION & CLINICAL RECOMMENDATIONS

# =============================================================================



@app.post("/recommend", tags=["AI Nutrition Recommendations"])

def get_recommendations(profile: PatientProfileRequest):

    """

    Evaluates patient profile and blood glucose against the Indian Food Nutrition Dataset.

    """

    try:

        profile_dict = profile.model_dump() if hasattr(profile, "model_dump") else profile.dict()

        results = recommender_engine.recommend_for_patient(

            patient_profile=profile_dict,

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





@app.post("/recommend_activities", tags=["AI Activity Recommendations"])

def recommend_activities(request: ActivityRecommendationRequest):

    """

    Evaluates patient glucose, fitness tier, and history against 2024 Compendium benchmarks.

    """

    try:

        req_dict = request.model_dump() if hasattr(request, "model_dump") else request.dict()

        results = activity_engine.generate_recommendations(req_dict)

        return {

            "success": results.get("success", False),

            "message": results.get("message", "Activity recommendations generated successfully."),

            "data": results

        }

    except Exception as e:

        raise HTTPException(

            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,

            detail=f"Error evaluating activity recommendations: {str(e)}"

        )





@app.get("/search", tags=["Food Explorer"])

def search_food(

    query: Optional[str] = Query(None, description="Partial or full dish name"),

    category: Optional[str] = Query(None, description="Category filter"),

    diet_type: Optional[str] = Query(None, description="Diet filter"),

    max_carbs: Optional[float] = Query(None, description="Maximum carbohydrates (g)"),

    min_protein: Optional[float] = Query(None, description="Minimum protein (g)"),

    max_sugar: Optional[float] = Query(None, description="Maximum free sugar (g)"),

    max_calories: Optional[float] = Query(None, description="Maximum calories (kcal)"),

    sort_by: str = Query("name"),

    sort_order: str = Query("asc"),

    page: int = Query(1, ge=1),

    limit: int = Query(30, ge=1, le=100)

):

    try:

        return {

            "success": True,

            "message": "Search completed.",

            "data": dataset_manager.search_dishes(

                query=query, category=category, diet_type=diet_type,

                max_carbs=max_carbs, min_protein=min_protein,

                max_sugar=max_sugar, max_calories=max_calories,

                sort_by=sort_by, sort_order=sort_order,

                page=page, limit=limit

            )

        }

    except Exception as e:

        raise HTTPException(

            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,

            detail=f"Error searching food dataset: {str(e)}"

        )





@app.get("/food/id/{item_id}", tags=["Food Explorer"])

def get_food_details_by_id(item_id: int):

    food = dataset_manager.get_by_id(item_id)

    if not food:

        raise HTTPException(status_code=404, detail=f"Dish ID {item_id} not found.")

    return {

        "success": True,

        "message": "Dish details retrieved.",

        "data": {**food, "glycemic_details": DiabetesRecommendationEngine._compute_glycemic_impact(food)}

    }





@app.get("/food/{dish_name}", tags=["Food Explorer"])

def get_food_details(dish_name: str):

    decoded = unquote(dish_name).strip()

    if decoded.isdigit():

        return get_food_details_by_id(int(decoded))

    food = dataset_manager.get_by_name(decoded)

    if not food:

        raise HTTPException(status_code=404, detail=f"Dish '{decoded}' not found.")

    return {

        "success": True,

        "message": "Dish details retrieved.",

        "data": {**food, "glycemic_details": DiabetesRecommendationEngine._compute_glycemic_impact(food)}

    }





@app.get("/categories", tags=["Dataset"])

def list_categories():

    if dataset_manager.df is None:

        return {"categories": [], "diet_types": []}

    return {

        "categories": sorted(list(dataset_manager.df["category"].dropna().unique())),

        "diet_types": sorted(list(dataset_manager.df["diet_type"].dropna().unique()))

    }





@app.get("/stats", tags=["Dataset"])

def dataset_stats():

    return dataset_manager.get_stats()



# =============================================================================

# APPLICATION ENTRYPOINT (DEFAULT PORT 8000)

# =============================================================================



if __name__ == "__main__":

    import uvicorn

    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)


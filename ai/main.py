import os
import sys
import logging
from typing import Optional, Dict, Any
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, field_validator

from ollama_service import OllamaService

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("DiaWell.Main")

# Initialize Ollama service pointing to local instance
ollama_service = OllamaService()

@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Lifespan event handler to verify and log local Ollama model detection on startup.
    """
    logger.info("Initializing DiaWell Local AI Service (FastAPI)...")
    try:
        health = ollama_service.health_check()
        if health.get("ollama_connected"):
            logger.info(f"Ollama connected successfully at {health.get('host')}.")
            logger.info(f"Installed models detected: {health.get('installed_models')}")
            logger.info(f"Active chat model selected: '{health.get('active_model')}'")
        else:
            logger.warning(f"Ollama not reachable at startup: {health.get('error')}")
    except Exception as e:
        logger.warning(f"Startup check warning: {e}")
    yield
    logger.info("DiaWell Local AI Service is shutting down.")

# Create FastAPI application
app = FastAPI(
    title="DiaWell Local Python AI Service",
    description="Local AI Service powered exclusively by locally installed Ollama models for DiaWell.",
    version="1.0.0",
    lifespan=lifespan
)

# Enable CORS for local development and API access
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# -----------------------------------------------------------------------------
# Request & Response Models
# -----------------------------------------------------------------------------
class ChatRequest(BaseModel):
    message: str = Field(..., description="User message or question")
    model: Optional[str] = Field(None, description="Optional installed Ollama model name to use")

    @field_validator("message")
    @classmethod
    def validate_message(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Message cannot be empty or only whitespace.")
        return v.strip()

# -----------------------------------------------------------------------------
# Standardized Exception Handlers (ensuring success, message, response format)
# -----------------------------------------------------------------------------
@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    errors = exc.errors()
    error_details = []
    for err in errors:
        loc = " -> ".join(str(l) for l in err.get("loc", []))
        msg = err.get("msg", "Validation error")
        error_details.append(f"{loc}: {msg}")
    
    error_summary = "; ".join(error_details) if error_details else "Invalid request body."
    return JSONResponse(
        status_code=status.HTTP_400_BAD_REQUEST,
        content={
            "success": False,
            "message": f"Input validation failed: {error_summary}",
            "response": None
        }
    )

@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    return JSONResponse(
        status_code=exc.status_code,
        content={
            "success": False,
            "message": str(exc.detail),
            "response": None
        }
    )

@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled exception on {request.url.path}: {exc}", exc_info=True)
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "success": False,
            "message": f"Internal server error: {str(exc)}",
            "response": None
        }
    )

# -----------------------------------------------------------------------------
# Endpoints
# -----------------------------------------------------------------------------
@app.get("/", summary="Health Check")
async def health_check():
    """
    Health check endpoint verifying the service status and local Ollama connection.
    Reads actual installed model names directly from Ollama.
    """
    health = ollama_service.health_check()

    if health.get("ollama_connected"):
        return {
            "success": True,
            "message": "DiaWell Local AI Assistant service is healthy and connected to Ollama.",
            "response": {
                "status": "healthy",
                "service": "DiaWell Local Python AI Service",
                "active_model": health.get("active_model"),
                "installed_models": health.get("installed_models"),
                "total_models": health.get("models_count", 0),
                "ollama_host": health.get("host")
            }
        }
    else:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={
                "success": False,
                "message": f"Local Ollama service is unavailable: {health.get('error')}",
                "response": {
                    "status": "unhealthy",
                    "ollama_host": health.get("host"),
                    "details": health.get("error")
                }
            }
        )

@app.post("/chat", summary="Chat with Local Ollama AI")
async def chat_endpoint(payload: ChatRequest):
    """
    Receives user message and returns contextual AI response from locally installed Ollama.
    Reads the actual installed Ollama model name instead of assuming one.
    """
    try:
        result = ollama_service.generate_chat_response(
            message=payload.message,
            requested_model=payload.model
        )

        model_used = result.get("model", "local-ollama")
        return {
            "success": True,
            "message": f"AI response generated successfully using '{model_used}'.",
            "response": result.get("response")
        }

    except ConnectionError as ce:
        logger.error(f"Ollama connection error: {ce}")
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={
                "success": False,
                "message": f"Cannot connect to local Ollama service: {str(ce)}",
                "response": None
            }
        )
    except ValueError as ve:
        logger.warning(f"Invalid model or input: {ve}")
        return JSONResponse(
            status_code=status.HTTP_400_BAD_REQUEST,
            content={
                "success": False,
                "message": str(ve),
                "response": None
            }
        )
    except Exception as e:
        logger.error(f"Unexpected error in /chat: {e}", exc_info=True)
        return JSONResponse(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            content={
                "success": False,
                "message": f"Failed to generate AI response: {str(e)}",
                "response": None
            }
        )

if __name__ == "__main__":
    import uvicorn
    # Default port 8000 for local AI service
    port = int(os.getenv("PORT", "8000"))
    logger.info(f"Starting server on http://127.0.0.1:{port}")
    uvicorn.run("main:app", host="127.0.0.1", port=port, reload=True)


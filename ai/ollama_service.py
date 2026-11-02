import os
import logging
from typing import List, Optional, Dict, Any
import ollama

logger = logging.getLogger("DiaWell.OllamaService")

EMBEDDING_KEYWORDS = ["embed", "bert", "bge", "all-minilm"]

DEFAULT_SYSTEM_PROMPT = (
    "You are Dr. Jarvis, the friendly AI health assistant in the DiaWell diabetes wellness app.\n\n"
    "Follow these strict communication rules:\n"
    "1. Short & Conversational: Keep responses brief, natural, and conversational. Default answer length is 2 to 5 sentences.\n"
    "2. Simple English: Use plain, everyday language that beginners easily understand. Avoid complicated medical terms unless briefly explained.\n"
    "3. No Repetition: Never repeat or echo the user's question. Do not use filler introductions like 'Sure!', 'Here is the answer', or 'That is a great question'. Jump straight into the helpful response.\n"
    "4. Simple Questions: Answer directly in 1 to 3 sentences.\n"
    "5. Complex Questions: Use short bullet points with a maximum of 5 bullet points in total.\n"
    "6. Medical Safety: Provide safe, accurate guidance for diabetes and wellness questions. Never omit essential medical warnings (such as immediate treatment for hypoglycemia below 70 mg/dL or consulting a doctor before altering medications), but state them concisely.\n"
    "7. Detailed Requests: If the user explicitly asks for detailed explanations, elaboration, or in-depth steps, provide more detail.\n"
    "8. No Repeated Conclusions: Do not add repetitive boilerplate conclusions or generic closing disclaimers at the end of every message."
)

class OllamaService:
    def __init__(self, host: Optional[str] = None):
        """
        Initialize the Ollama service pointing to the local Ollama instance.
        Defaults to http://127.0.0.1:11434 or the OLLAMA_HOST environment variable.
        """
        self.host = host or os.getenv("OLLAMA_HOST", "http://127.0.0.1:11434")
        self.client = ollama.Client(host=self.host)
        self._cached_model: Optional[str] = None

    def get_installed_models(self) -> List[str]:
        """
        Dynamically query the local Ollama instance to retrieve all currently installed models.
        Does not assume or hardcode any model names.
        """
        try:
            response = self.client.list()
            models_list = getattr(response, "models", [])
            installed_names = []

            for item in models_list:
                name = getattr(item, "model", None) or getattr(item, "name", None)
                if not name and isinstance(item, dict):
                    name = item.get("model") or item.get("name")
                if name:
                    installed_names.append(str(name))

            return installed_names
        except Exception as e:
            logger.error(f"Error communicating with local Ollama service at {self.host}: {e}")
            raise ConnectionError(
                f"Could not connect to local Ollama instance at {self.host}. "
                f"Please ensure Ollama is running ('ollama serve' or system tray app). Details: {e}"
            )

    def get_active_model(self, requested_model: Optional[str] = None) -> str:
        """
        Read the actual installed Ollama model name instead of assuming a model name.
        1. If a specific model is requested and installed, use it.
        2. If OLLAMA_MODEL environment variable matches an installed model, use it.
        3. Dynamically filter out embedding-only models and pick the installed chat model.
        4. If no models installed, raise a descriptive RuntimeError.
        """
        installed = self.get_installed_models()

        if not installed:
            raise RuntimeError(
                f"No models found in local Ollama at {self.host}. "
                "Please install a model first by running: 'ollama pull qwen2.5:3b' or 'ollama pull llama3.1:8b'."
            )

        # 1. Check if user requested a specific model that is installed
        if requested_model:
            # Match exact or prefix without tag
            for m in installed:
                if m.lower() == requested_model.lower() or m.split(":")[0].lower() == requested_model.lower():
                    return m
            raise ValueError(
                f"Requested model '{requested_model}' is not installed locally. "
                f"Currently installed models: {installed}"
            )

        # 2. Check environment variable override
        env_model = os.getenv("OLLAMA_MODEL")
        if env_model:
            for m in installed:
                if m.lower() == env_model.lower() or m.split(":")[0].lower() == env_model.lower():
                    return m

        # 3. Filter out embedding models to pick the best generation/chat LLM
        chat_models = [
            m for m in installed
            if not any(k in m.lower() for k in EMBEDDING_KEYWORDS)
        ]

        if chat_models:
            # Use the first available chat model installed locally
            self._cached_model = chat_models[0]
            return chat_models[0]

        # 4. Fallback to any installed model if distinction cannot be made
        self._cached_model = installed[0]
        return installed[0]

    def generate_chat_response(
        self,
        message: str,
        requested_model: Optional[str] = None,
        system_prompt: Optional[str] = None,
        rag_context: Optional[str] = None,
        sources: Optional[List[Dict[str, Any]]] = None
    ) -> Dict[str, Any]:
        """
        Communicate with the locally installed Ollama model to generate a concise, natural chat response.
        If rag_context is provided, grounds the answer in verified dataset facts with source attribution.
        Uses only local Ollama models; no external APIs.
        """
        if not message or not message.strip():
            raise ValueError("User message cannot be empty or blank.")

        active_model = self.get_active_model(requested_model)
        sys_prompt = system_prompt or DEFAULT_SYSTEM_PROMPT

        # Determine if user explicitly requested detailed explanation
        msg_lower = message.lower()
        detail_keywords = ["detail", "detailed", "explain in detail", "elaborate", "in depth", "in-depth", "comprehensive", "step by step", "step-by-step"]
        is_detailed = any(k in msg_lower for k in detail_keywords)

        # Configure Ollama generation options: ~200-250 token limit to guarantee concise answers without cutting off critical warnings
        token_limit = 500 if is_detailed else (260 if rag_context else 200)

        if rag_context:
            user_content = (
                f"[VERIFIED DATASET INFORMATION]\n{rag_context}\n\n"
                f"[USER QUESTION]\n{message.strip()}\n\n"
                "INSTRUCTIONS:\n"
                "1. Answer the user's question directly using the verified dataset information above.\n"
                "2. State actual values (calories, carbohydrates, MET values, active salts, strengths) accurately from the verified information.\n"
                "3. Do NOT invent or assume values not present in the verified dataset.\n"
                "4. Keep the answer concise (normally 2 to 5 sentences).\n"
                "5. Never recommend starting, stopping, or altering prescription medications or dosages.\n"
                "6. Conclude with a brief source credit (e.g. '(Source: Indian Food Nutrition Dataset)' or '(Source: 2024 Adult Compendium)')."
            )
        else:
            user_content = message.strip()

        messages = [
            {"role": "system", "content": sys_prompt},
            {"role": "user", "content": user_content}
        ]

        options = {
            "num_predict": token_limit,
            "temperature": 0.6
        }

        try:
            logger.info(f"Dispatching chat request to local Ollama model: '{active_model}' (num_predict={token_limit}, RAG={'Yes' if rag_context else 'No'})")
            response = self.client.chat(
                model=active_model,
                messages=messages,
                options=options
            )

            # Extract message content
            msg_obj = response.get("message") if isinstance(response, dict) else getattr(response, "message", None)
            if msg_obj:
                reply_text = msg_obj.get("content") if isinstance(msg_obj, dict) else getattr(msg_obj, "content", "")
            else:
                reply_text = str(response)

            return {
                "response": reply_text.strip(),
                "model": active_model,
                "done": True,
                "has_rag": bool(rag_context)
            }

        except Exception as e:
            logger.error(f"Error during Ollama chat generation with model '{active_model}': {e}")
            raise RuntimeError(f"Ollama chat generation failed: {e}")

    def health_check(self) -> Dict[str, Any]:
        """
        Check connectivity to the local Ollama instance and report installed models.
        """
        try:
            installed = self.get_installed_models()
            active = self.get_active_model() if installed else None
            return {
                "ollama_connected": True,
                "host": self.host,
                "active_model": active,
                "installed_models": installed,
                "models_count": len(installed)
            }
        except Exception as e:
            return {
                "ollama_connected": False,
                "host": self.host,
                "active_model": None,
                "installed_models": [],
                "error": str(e)
            }


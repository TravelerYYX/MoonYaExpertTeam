"""MoonYa Web Search Package"""


def register(name, description, params):
    """Dummy decorator that returns the function unchanged."""
    def decorator(func):
        return func
    return decorator


from .web_search import web_search, web_fetch  # noqa: E402

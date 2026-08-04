# Session working memory (artifacts + editor.refs)

Ahentic keeps session-scoped working memory with separate namespaces: payload artifacts for large drafts (`from_memory`) and `editor.refs` for opaque block ids. The model never invents Gutenberg clientIds; refs are session-backed and validated on every browser tool (miss wipes the map). Pending tools carry artifact keys only; bodies expand at execute time. This beats stuffing drafts into chat and guessing UUIDs.

# Content-aware budgets and run liveness

Long-form writing is a first-class free outcome, so runs use content-aware step/output budgets (higher than normal chat) plus chunked artifacts instead of tiny global caps that force stub articles. Separately, every busy session exposes progress plus a worker heartbeat so the UI can tell slow-but-alive from dead-stuck and offer Continue — mixed “not doing anything” failures are treated as product bugs against that liveness contract.

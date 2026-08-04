# Agent completion requires plan and tiered verification

Ask mode ends when a good answer is delivered. Agent mode requires an explicit plan for multi-step work (≥2 tools or any write) and tiered verification (tool success for readonly; read-after-write for mutations; verify + HITL for publish/destructive). Humans gate danger via HITL policy, not ordinary completion. This rejects “model said done” as sufficient for write work.

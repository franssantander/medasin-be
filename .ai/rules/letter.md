---
paths:
  - 'app/Services/Letter/**'
---

# Letter

## Letter exports use source snapshots
Letter exports persist a source_hash and a generated page manifest. The queued job marks the letter exported only when the current letter hash still matches the export snapshot; any letter edit resets status/exported_at. Pagination is deterministic and capped at the configured 10-page limit until a binary renderer is added.

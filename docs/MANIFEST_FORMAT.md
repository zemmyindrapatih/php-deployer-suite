# Manifest Format

Every deploy zip contains a `manifest.json` at its root, plus a `files/`
directory holding the actual content for anything being added or replaced.

```json
{
  "version": 1,
  "generated_at": "2026-09-13T12:00:00Z",
  "from_ref": "v1.2.0",
  "to_ref": "v1.3.0",
  "chunk_size_hint": 2097152,
  "add": [
    { "path": "app/NewFeature.php", "sha256": "..." }
  ],
  "replace": [
    { "path": "index.php", "sha256": "..." }
  ],
  "delete": [
    "app/OldFeature.php"
  ]
}
```

## Fields

- `version` - manifest schema version (currently always `1`).
- `generated_at` - UTC timestamp the manifest was built.
- `from_ref` / `to_ref` - the git refs diffed to produce this deploy.
- `chunk_size_hint` - the chunk size (bytes) the sender was configured with, informational only.
- `add` - files that don't exist yet on the target and should be created. Each entry is `{path, sha256}` where `sha256` is the hash of the file's content as it exists at `files/<path>` inside the zip.
- `replace` - files that already exist on the target and should be overwritten. Same shape as `add`.
- `delete` - a plain list of relative paths to remove from the target. No corresponding entry exists under `files/`.

## Zip layout

```
manifest.json
files/
  app/NewFeature.php
  index.php
```

Only `add` and `replace` entries have a corresponding file under `files/`;
`delete` entries are metadata-only.

## Renames

`GitDiffer` treats a git rename as a `delete` of the old path plus an `add`
of the new path — there is no dedicated "rename" manifest entry.

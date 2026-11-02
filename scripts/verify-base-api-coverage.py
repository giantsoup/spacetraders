#!/usr/bin/env python3
"""Read-only integrity/drift/completion checks for the complete official API inventory."""
import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import sys
import urllib.request

METHODS = {'get', 'post', 'patch', 'put', 'delete', 'head', 'options', 'trace'}
STATES = {'source_mapped', 'partial', 'aggregate_only', 'local_only', 'missing'}


class CoverageError(ValueError):
    pass


def resolve(spec, value):
    if '$ref' not in value:
        return value
    ref = value['$ref']
    if not ref.startswith('#/'):
        raise CoverageError('External schema references require an explicit audit: ' + ref)
    result = spec
    for key in ref[2:].split('/'):
        result = result[key.replace('~1', '/').replace('~0', '~')]
    return result


def operations(spec):
    result = {}
    for path, item in spec['paths'].items():
        for method, operation in item.items():
            if method.lower() not in METHODS:
                continue
            identity = operation['operationId']
            if identity in result:
                raise CoverageError('Duplicate official operation ID: ' + identity)
            request = resolve(spec, operation.get('requestBody', {}))
            schema = resolve(spec, request.get('content', {}).get('application/json', {}).get('schema', {}))
            params = [resolve(spec, p) for p in item.get('parameters', []) + operation.get('parameters', [])]
            result[identity] = {
                'method': method.upper(), 'path': path,
                'request_fields': sorted(schema.get('properties', {})),
                'query_fields': sorted({p['name'] for p in params if p.get('in') == 'query'}),
            }
    return result


def owned_file(root, relative):
    if not isinstance(relative, str) or Path(relative).is_absolute():
        raise CoverageError('Evidence/source paths must be repository-relative.')
    file = (root / relative).resolve()
    if root.resolve() not in file.parents or not file.is_file():
        raise CoverageError('Missing or external artifact: ' + relative)
    return file


def evidence(root, record, operation, stage, source_commit):
    if not isinstance(record, dict):
        raise CoverageError(operation + ': accepted operation lacks ' + stage + ' evidence')
    file = owned_file(root, record.get('artifact'))
    if hashlib.sha256(file.read_bytes()).hexdigest() != record.get('sha256'):
        raise CoverageError(operation + ': ' + stage + ' artifact hash mismatch')
    proof = json.loads(file.read_text())
    if (proof.get('operation_id'), proof.get('stage'), proof.get('source_commit'), proof.get('result')) != (operation, stage, source_commit, 'passed'):
        raise CoverageError(operation + ': invalid or stale ' + stage + ' evidence identity')


def validate(manifest, spec, root, require_complete=False):
    if manifest.get('schema_version') != 1:
        raise CoverageError('Unsupported inventory schema_version')
    expected = operations(spec)
    rows = manifest['operations']
    ids = [row['id'] for row in rows]
    if len(ids) != len(set(ids)):
        raise CoverageError('Duplicate inventory operation ID')
    if set(ids) != set(expected):
        raise CoverageError('Operation inventory mismatch: missing=' + str(sorted(set(expected) - set(ids))) + ', extra=' + str(sorted(set(ids) - set(expected))))
    if manifest['source']['version'] != spec['info']['version']:
        raise CoverageError('Official version and inventory version differ')
    tasks = manifest['tasks']
    visiting, visited = set(), set()

    def visit(task):
        if task in visiting:
            raise CoverageError('Task dependency cycle at ' + task)
        if task in visited:
            return
        if task not in tasks:
            raise CoverageError('Unknown task dependency: ' + task)
        visiting.add(task)
        for dependency in tasks[task]['depends_on']:
            visit(dependency)
        visiting.remove(task)
        visited.add(task)

    for task in tasks:
        visit(task)
    for relative, digest in manifest['source_files'].items():
        if hashlib.sha256(owned_file(root, relative).read_bytes()).hexdigest() != digest:
            raise CoverageError('Source changed; re-audit mapping before claiming coverage: ' + relative)
    accepted = 0
    for row in rows:
        identity = row['id']
        for field, value in expected[identity].items():
            if row.get(field) != value:
                raise CoverageError(identity + ': method/path/request/query drift in ' + field)
        if row['task'] not in tasks or row['implementation'] not in STATES:
            raise CoverageError(identity + ': invalid task or implementation classification')
        for reference in row['source_evidence']:
            if reference['path'] not in manifest['source_files']:
                raise CoverageError(identity + ': unhashed source reference')
            if reference['contains'] not in owned_file(root, reference['path']).read_text():
                raise CoverageError(identity + ': stale source anchor')
        acceptance = row['acceptance']
        if acceptance['state'] not in {'not_accepted', 'accepted'}:
            raise CoverageError(identity + ': invalid acceptance state')
        if acceptance['state'] == 'accepted':
            if row['implementation'] != 'source_mapped':
                raise CoverageError(identity + ': cannot accept missing, partial, aggregate-only or local-only implementation')
            if not row['source_evidence']:
                raise CoverageError(identity + ': accepted operation lacks a source mapping')
            for stage in ['contract', 'browser', 'live']:
                evidence(root, acceptance.get(stage), identity, stage, manifest['source']['source_commit'])
            accepted += 1
    if require_complete and accepted != len(rows):
        raise CoverageError(f'Foundation incomplete: {accepted}/{len(rows)} operations accepted. Pending cases cannot be counted as complete.')
    return len(rows), accepted, Counter(row['implementation'] for row in rows)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--refresh', action='store_true', help='Read official public specification and fail on drift; never update files.')
    parser.add_argument('--require-complete', action='store_true', help='Require full operation acceptance, including live evidence.')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    manifest = json.loads((root / 'docs/api/base-api-coverage.json').read_text())
    raw = owned_file(root, manifest['source']['snapshot']).read_bytes()
    if hashlib.sha256(raw).hexdigest() != manifest['source']['sha256']:
        raise CoverageError('Pinned specification hash mismatch')
    spec = json.loads(raw)
    count, accepted, counts = validate(manifest, spec, root, args.require_complete)
    if args.refresh:
        request = urllib.request.Request(manifest['source']['url'], headers={'Accept': 'application/json'})
        with urllib.request.urlopen(request, timeout=20) as response:
            current = json.load(response)
        if current != spec:
            before, after = operations(spec), operations(current)
            raise CoverageError('Official specification drift: added=' + str(sorted(set(after) - set(before))) + ', removed=' + str(sorted(set(before) - set(after))) + '. Re-audit all schema, field and semantic changes; version equality alone is insufficient.')
        print('PASS official specification matches the complete pinned JSON, including schemas.')
    print(f'PASS inventory/source integrity: {count} operations; {accepted} accepted for foundation completion.')
    print('Source classifications: ' + json.dumps(dict(sorted(counts.items()))))
    print('Integrity is not gameplay acceptance. No gameplay request or repository mutation was made.')


if __name__ == '__main__':
    try:
        main()
    except (CoverageError, KeyError, TypeError, OSError, ValueError) as exception:
        print('FAIL: ' + str(exception), file=sys.stderr)
        sys.exit(1)

"""Guard inventory completeness and prevent unsupported completion claims."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/verify-base-api-coverage.py'
SPEC = importlib.util.spec_from_file_location('coverage_check', SCRIPT)
CHECK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECK)


class CoverageTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        source = self.root / 'client.txt'
        source.write_text('named-operation')
        self.spec = {'info': {'version': 'test'}, 'paths': {'/item': {'post': {
            'operationId': 'named-operation', 'requestBody': {'content': {'application/json': {
                'schema': {'properties': {'units': {'type': 'integer'}}}}}}}}}}
        self.manifest = {
            'schema_version': 1,
            'source': {'version': 'test', 'source_commit': 'source-revision'},
            'source_files': {'client.txt': hashlib.sha256(source.read_bytes()).hexdigest()},
            'tasks': {'FND-01': {'depends_on': []}},
            'operations': [{
                'id': 'named-operation', 'method': 'POST', 'path': '/item',
                'request_fields': ['units'], 'query_fields': [],
                'task': 'FND-01', 'implementation': 'source_mapped',
                'source_evidence': [{'path': 'client.txt', 'contains': 'named-operation'}],
                'acceptance': {'state': 'not_accepted'},
            }],
        }

    def check(self, complete=False):
        return CHECK.validate(self.manifest, self.spec, self.root, complete)

    def accept(self):
        record = self.manifest['operations'][0]['acceptance']
        record['state'] = 'accepted'
        for stage in ['contract', 'browser', 'live', 'aggregate_equivalence']:
            file = self.root / (stage + '.json')
            file.write_text(json.dumps({'operation_id': 'named-operation', 'stage': stage,
                                       'source_commit': 'source-revision', 'result': 'passed'}))
            record[stage] = {'artifact': file.name, 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()}

    def test_inventory_integrity_is_not_completion(self):
        self.assertEqual(self.check()[:2], (1, 0))
        with self.assertRaisesRegex(CHECK.CoverageError, 'Foundation incomplete'):
            self.check(complete=True)

    def test_missing_and_duplicate_operations_fail(self):
        original = copy.deepcopy(self.manifest['operations'])
        self.manifest['operations'] = []
        with self.assertRaisesRegex(CHECK.CoverageError, 'inventory mismatch'):
            self.check()
        self.manifest['operations'] = original + original
        with self.assertRaisesRegex(CHECK.CoverageError, 'Duplicate inventory'):
            self.check()

    def test_new_official_operation_fails(self):
        self.spec['paths']['/new'] = {'get': {'operationId': 'new-read'}}
        with self.assertRaisesRegex(CHECK.CoverageError, 'new-read'):
            self.check()

    def test_parameter_and_method_drift_fail(self):
        self.spec['paths']['/item']['post']['requestBody']['content']['application/json']['schema']['properties']['fromCargo'] = {'type': 'boolean'}
        with self.assertRaisesRegex(CHECK.CoverageError, 'request_fields'):
            self.check()
        self.spec['paths']['/item']['patch'] = self.spec['paths']['/item'].pop('post')
        with self.assertRaisesRegex(CHECK.CoverageError, 'method'):
            self.check()

    def test_unknown_task_and_cycle_fail(self):
        self.manifest['operations'][0]['task'] = 'unknown'
        with self.assertRaisesRegex(CHECK.CoverageError, 'invalid task'):
            self.check()
        self.manifest['operations'][0]['task'] = 'FND-01'
        self.manifest['tasks']['FND-01']['depends_on'] = ['FND-01']
        with self.assertRaisesRegex(CHECK.CoverageError, 'dependency cycle'):
            self.check()

    def test_stale_source_and_source_anchor_fail(self):
        (self.root / 'client.txt').write_text('changed')
        with self.assertRaisesRegex(CHECK.CoverageError, 'Source changed'):
            self.check()
        self.manifest['source_files']['client.txt'] = hashlib.sha256(b'changed').hexdigest()
        with self.assertRaisesRegex(CHECK.CoverageError, 'stale source anchor'):
            self.check()

    def test_acceptance_requires_all_three_evidence_stages(self):
        self.manifest['operations'][0]['acceptance']['state'] = 'accepted'
        with self.assertRaisesRegex(CHECK.CoverageError, 'lacks contract'):
            self.check()
        self.accept()
        del self.manifest['operations'][0]['acceptance']['live']
        with self.assertRaisesRegex(CHECK.CoverageError, 'lacks live'):
            self.check()

    def test_partial_and_local_implementations_cannot_be_accepted(self):
        self.accept()
        for status in ['partial', 'local_only', 'missing', 'aggregate_only']:
            self.manifest['operations'][0]['implementation'] = status
            with self.assertRaisesRegex(CHECK.CoverageError, 'cannot accept'):
                self.check()

    def test_evidence_hash_and_revision_are_bound(self):
        self.accept()
        (self.root / 'live.json').write_text('{}')
        with self.assertRaisesRegex(CHECK.CoverageError, 'hash mismatch'):
            self.check()
        self.accept()
        self.manifest['source']['source_commit'] = 'different-revision'
        with self.assertRaisesRegex(CHECK.CoverageError, 'stale contract'):
            self.check()

    def test_aggregate_data_cannot_retire_a_published_read(self):
        self.accept()
        self.manifest['operations'][0]['implementation'] = 'aggregate_only'
        with self.assertRaisesRegex(CHECK.CoverageError, 'cannot accept'):
            self.check(complete=True)

    def test_artifacts_cannot_escape_repository(self):
        self.accept()
        self.manifest['operations'][0]['acceptance']['live']['artifact'] = '../outside.json'
        with self.assertRaisesRegex(CHECK.CoverageError, 'external artifact'):
            self.check()

    def test_complete_record_passes_without_running_any_gameplay(self):
        self.accept()
        self.assertEqual(self.check(complete=True)[:2], (1, 1))


class DocumentationTest(unittest.TestCase):
    def setUp(self):
        self.root = SCRIPT.parent.parent
        self.manifest = json.loads((self.root / 'docs/api/base-api-coverage.json').read_text())

    def test_readable_inventory_matches_each_manifest_row(self):
        text = (self.root / 'docs/api/base-api-inventory.md').read_text()
        actual = [line for line in text.splitlines() if re.match(r'^\| `[^`]+` \|', line)]
        expected = ['| `' + row['id'] + '` | `' + row['method'] + ' ' + row['path'] + '` | '
                    + row['implementation'] + ' | ' + row['task'] + ' | ' + row['notes'] + ' |'
                    for row in self.manifest['operations']]
        self.assertEqual(actual, expected)

    def test_every_task_has_a_plan_section_and_every_operation_a_research_reference(self):
        plan = (self.root / 'docs/base-api-foundation-plan.md').read_text()
        self.assertEqual(set(re.findall(r'^### (FND-\d+)\.', plan, re.M)), set(self.manifest['tasks']))
        research = (self.root / 'docs/base-api-contract-research.md').read_text()
        for row in self.manifest['operations']:
            self.assertIn('`' + row['id'] + '`', research)

    def test_new_document_links_and_priority_notices_resolve(self):
        for relative in ['docs/base-api-foundation-plan.md', 'docs/base-api-contract-research.md', 'docs/api/base-api-inventory.md']:
            file = self.root / relative
            for target in re.findall(r'\]\(([^)]+)\)', file.read_text()):
                if target.startswith(('http:', 'https:', '#', '/')):
                    continue
                target = target.split('#', 1)[0]
                if target and '`' not in target:
                    self.assertTrue((file.parent / target).is_file(), (relative, target))
        for relative in ['README.md', 'docs/openai-automation-adviser-plan.md',
                         'docs/exploration-fuel-and-opportunity-recommendations.md',
                         'docs/local-automation-implementation-plan.md', 'docs/plan-implementation-audit.md']:
            self.assertIn('base-api-foundation-plan.md', (self.root / relative).read_text())


if __name__ == '__main__':
    unittest.main()

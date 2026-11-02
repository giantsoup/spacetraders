"""Offline tracking regression: implementation progress is not a frozen audit snapshot."""
import copy
import importlib.util
import json
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('tracking', 'scripts/verify-plan-tracking.py')
tracking = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tracking)


class TrackingTest(unittest.TestCase):
    def setUp(self):
        self.manifest = json.loads(pathlib.Path('docs/plan-tasks.json').read_text())
        self.task = copy.deepcopy(self.manifest['tasks'][1])
        self.issue = dict(number=self.task['issue_number'], html_url=self.task['issue_url'], state='open', body=self.task['pr_url'])
        self.files = [dict(filename=name, status='added') for name in self.task['audit_changed_files']]
        self.pr = dict(number=self.task['pr_number'], html_url=self.task['pr_url'], state='open', draft=True, merged_at=None,
                       base={'ref': 'main'}, head={'ref': self.task['branch'], 'sha': self.task['audit_head_sha']},
                       body=f"Refs #{self.issue['number']}", changed_files=len(self.files), auto_merge=None)

    def verify(self, snapshot=False):
        return tracking.verify_pair(self.task, self.issue, self.pr, self.files, snapshot)

    def test_original_snapshot_and_all_42_pairs(self):
        self.assertEqual(42, len(self.manifest['tasks']))
        tracking.validate_manifest(self.manifest)
        self.assertIn('matches recorded audit fields', self.verify(snapshot=True))

    def test_progress_and_removed_files_report_drift_without_claiming_acceptance(self):
        self.pr['head']['sha'] = 'b' * 40
        self.files.append(dict(filename='app/old.php', status='removed'))
        self.pr['changed_files'] += 1
        self.assertIn('changed since audit; acceptance not assessed', self.verify())
        with self.assertRaises(AssertionError):
            self.verify(snapshot=True)

    def test_ready_and_merged_lifecycle_are_trackable(self):
        self.pr['draft'] = False
        self.assertIn('ready for review', self.verify())
        self.pr.update(state='closed', merged_at='2026-11-01T22:00:00Z')
        self.issue['state'] = 'closed'
        self.assertIn('merged', self.verify())

    def test_wrong_branch_closed_unmerged_and_missing_links_fail(self):
        for target, key, value in [(self.pr['head'], 'ref', 'wrong-branch'), (self.pr, 'state', 'closed'),
                                   (self.issue, 'state', 'closed'), (self.pr, 'body', ''), (self.issue, 'body', '')]:
            original = target[key]
            target[key] = value
            with self.assertRaises(AssertionError):
                self.verify()
            target[key] = original

    def test_incomplete_pagination_fails(self):
        self.pr['changed_files'] = 101
        with self.assertRaisesRegex(AssertionError, 'incomplete file pagination'):
            self.verify()

    def test_duplicate_pair_and_cycle_fail(self):
        self.manifest['tasks'][1]['pr_number'] = 2
        with self.assertRaisesRegex(AssertionError, 'Duplicate pr_number'):
            tracking.validate_manifest(self.manifest)
        self.manifest['tasks'][1]['pr_number'] = self.task['pr_number']
        self.manifest['tasks'][0]['dependencies'] = ['DOC01']
        with self.assertRaisesRegex(AssertionError, 'Circular dependency'):
            tracking.validate_manifest(self.manifest)


if __name__ == '__main__':
    unittest.main()

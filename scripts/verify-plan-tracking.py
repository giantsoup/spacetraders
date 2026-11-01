#!/usr/bin/env python3
"""Validate plan coverage and optionally re-read every GitHub issue/PR pair."""
import argparse
import json
import pathlib
import subprocess

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--manifest', type=pathlib.Path, default=pathlib.Path('docs/plan-tasks.json'))
p.add_argument('--github', action='store_true', help='Read live GitHub state using authenticated gh; makes no mutations.')
a = p.parse_args()
m = json.loads(a.manifest.read_text())
repo = m['repository']
tasks = m['tasks']
ids = {t['id'] for t in tasks}
assert len(ids) == len(tasks), 'Duplicate task ID'
assert len({t['title'] for t in tasks}) == len(tasks), 'Duplicate title'
for task in tasks:
    assert set(task['dependencies']) <= ids, task['id']
    assert task['id'] not in task['dependencies'], task['id']
    assert task['evidence'] and task['acceptance'] and task['boundary'], task['id']
    for source in task['sources']:
        name, line = source.rsplit(':', 1)
        assert pathlib.Path(name).is_file(), source
        assert 0 < int(line) <= len(pathlib.Path(name).read_text().splitlines()), source

def walk(id, path):
    assert id not in path, f'Circular dependency: {path + [id]}'
    for dep in next(t for t in tasks if t['id'] == id)['dependencies']:
        walk(dep, path + [id])
for id in ids:
    walk(id, [])
if a.github:
    def read(route):
        return json.loads(subprocess.check_output(['gh', 'api', f'repos/{repo}/{route}'], text=True))
    issue_numbers, pr_numbers, branches = set(), set(), set()
    for task in tasks:
        issue = read(f"issues/{task['issue_number']}")
        pr = read(f"pulls/{task['pr_number']}")
        assert 'pull_request' not in issue, task['id']
        assert issue['state'] == 'open', task['id']
        assert pr['state'] == 'open' and pr['draft'], task['id']
        assert pr['base']['ref'] == 'main', task['id']
        assert pr['head']['ref'] == task['branch'], task['id']
        if task.get('head_sha'):
            assert pr['head']['sha'] == task['head_sha'], task['id']
        assert f"Refs #{issue['number']}" in pr['body'], task['id']
        assert pr['html_url'] in issue['body'], task['id']
        assert not pr.get('auto_merge'), task['id']
        files = read(f"pulls/{pr['number']}/files?per_page=100")
        assert {f['filename'] for f in files} == set(task['changed_files']), task['id']
        assert not any(f['status'] == 'removed' for f in files), task['id']
        assert not any(word in pr['body'].lower() for word in ['closes #', 'fixes #', 'resolves #']), task['id']
        for value, seen in [(issue['number'], issue_numbers), (pr['number'], pr_numbers), (pr['head']['ref'], branches)]:
            assert value not in seen, f'Duplicate pair: {task["id"]}'
            seen.add(value)
        print(f"Verified {task['id']}: issue #{issue['number']}, draft PR #{pr['number']}, {len(files)} files")
    assert len(issue_numbers) == len(pr_numbers) == len(branches) == len(tasks)
print(f'Validated {len(tasks)} unique tasks, source anchors and acyclic dependencies' + (' against live GitHub state.' if a.github else '.'))

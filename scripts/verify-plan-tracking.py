#!/usr/bin/env python3
"""Validate task coverage and read current GitHub tracking without claiming acceptance."""
import argparse
import json
import pathlib
import subprocess


def validate_manifest(manifest):
    tasks = manifest['tasks']
    ids = {task['id'] for task in tasks}
    assert len(ids) == len(tasks), 'Duplicate task ID'
    assert len({task['title'] for task in tasks}) == len(tasks), 'Duplicate title'
    for field in ['issue_number', 'pr_number', 'branch']:
        assert len({task[field] for task in tasks}) == len(tasks), f'Duplicate {field}'
    for task in tasks:
        assert set(task['dependencies']) <= ids, task['id']
        assert task['evidence'] and task['acceptance'] and task['boundary'], task['id']
        for kind, field in [('issues', 'issue_number'), ('pull', 'pr_number')]:
            url_field = 'issue_url' if kind == 'issues' else 'pr_url'
            assert task[url_field] == f"https://github.com/{manifest['repository']}/{kind}/{task[field]}", task['id']
        for source in task['sources']:
            name, line = source.rsplit(':', 1)
            assert pathlib.Path(name).is_file(), source
            assert 0 < int(line) <= len(pathlib.Path(name).read_text().splitlines()), source

    by_id = {task['id']: task for task in tasks}

    def walk(task_id, path):
        assert task_id not in path, f'Circular dependency: {path + [task_id]}'
        for dependency in by_id[task_id]['dependencies']:
            walk(dependency, path + [task_id])

    for task_id in ids:
        walk(task_id, [])


def verify_pair(task, issue, pr, files, snapshot=False):
    task_id = task['id']
    assert 'pull_request' not in issue, task_id
    assert issue['number'] == task['issue_number'] and pr['number'] == task['pr_number'], task_id
    assert issue['html_url'] == task['issue_url'] and pr['html_url'] == task['pr_url'], task_id
    merged = pr['merged_at'] is not None
    assert pr['state'] == 'open' or merged, f'{task_id}: closed unmerged PR'
    assert issue['state'] == 'open' or merged, f'{task_id}: closed issue before merge'
    assert pr['base']['ref'] == 'main', task_id
    assert pr['head']['ref'] == task['branch'], task_id
    assert f"Refs #{issue['number']}" in (pr['body'] or ''), task_id
    assert pr['html_url'] in (issue['body'] or ''), task_id
    assert not pr.get('auto_merge'), task_id
    assert not any(word in (pr['body'] or '').lower() for word in ['closes #', 'fixes #', 'resolves #']), task_id
    names = {file['filename'] for file in files}
    assert len(names) == len(files), f'{task_id}: duplicate file records'
    assert len(files) == pr['changed_files'], f'{task_id}: incomplete file pagination'
    old_head = task['audit_head_sha']
    old_files = set(task['audit_changed_files'])
    if snapshot:
        assert issue['state'] == 'open' and pr['state'] == 'open' and pr['draft'], task_id
        if old_head:
            assert pr['head']['sha'] == old_head, f'{task_id}: head advanced since audit'
        assert names == old_files, f'{task_id}: file set changed since audit'
        assert not any(file['status'] == 'removed' for file in files), task_id
    state = 'merged' if merged else ('draft' if pr['draft'] else 'ready for review')
    drift = 'changed since audit' if (old_head and pr['head']['sha'] != old_head) or names != old_files else 'matches recorded audit fields'
    return f"{task_id}: issue #{issue['number']} {issue['state']}, PR #{pr['number']} {state}, head {pr['head']['sha']}, {len(files)} files; {drift}; acceptance not assessed"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', type=pathlib.Path, default=pathlib.Path('docs/plan-tasks.json'))
    parser.add_argument('--github', action='store_true', help='Read live GitHub state using authenticated gh; makes no mutations.')
    parser.add_argument('--snapshot', action='store_true', help='Require original open/draft state, audit head and file set (expected to fail after implementation).')
    args = parser.parse_args()
    if args.snapshot and not args.github:
        parser.error('--snapshot requires --github')
    manifest = json.loads(args.manifest.read_text())
    validate_manifest(manifest)
    if args.github:
        def read(route):
            return json.loads(subprocess.check_output(['gh', 'api', f"repos/{manifest['repository']}/{route}"], text=True))

        for task in manifest['tasks']:
            issue = read(f"issues/{task['issue_number']}")
            pr = read(f"pulls/{task['pr_number']}")
            pages = json.loads(subprocess.check_output(['gh', 'api', '--paginate', '--slurp', f"repos/{manifest['repository']}/pulls/{pr['number']}/files?per_page=100"], text=True))
            files = [file for page in pages for file in page]
            print(verify_pair(task, issue, pr, files, args.snapshot), flush=True)
    print(f"Validated {len(manifest['tasks'])} unique tasks, source anchors and acyclic dependencies" + (' against live GitHub tracking; feature acceptance not assessed.' if args.github else '.'))


if __name__ == '__main__':
    main()

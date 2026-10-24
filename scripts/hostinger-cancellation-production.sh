#!/usr/bin/env bash
# Release only the staged cancellation/FAQ and review-layout scope. Never activate features.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
source_sha=$GITHUB_SHA
mode=${CANCELLATION_PRODUCTION_MODE:-inspect}
[[ $run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
[[ $mode == inspect || $mode == apply ]]
[[ ${GITHUB_REF_NAME:-} == inspect-cancellation-production || ${GITHUB_REF_NAME:-} == deploy-cancellation-production ]]
if [[ $mode == apply ]]; then [[ $GITHUB_REF_NAME == deploy-cancellation-production ]]; fi
work=$(mktemp -d "$RUNNER_TEMP/petish-cancellation-production.XXXXXXXX")
remote="/home/u839205597/.petish-cancellation-production-$run"
mkdir -m 700 "$work/bundle" "$work/bundle/payload"
ssh_ready=0
remote_tools_removed=0
rollback_needed=0
rollback_attempted=0
rollback_verified=0
staging_source_verified=0
operation_verified=0
result_status=failure
category=prerequisites
published_commit=

screen_phase() {
    local phase=$1
    python3 scripts/sanitize-cancellation-production.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
        > "$work/$phase-screen.private.stdout" 2> "$work/$phase-screen.private.stderr"
}

remote_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in inspect|apply|faq|verify|resume|commit|rollback|rollback-finalize) ;; *) return 1 ;; esac
    "${petish_ssh[@]}" "set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '$remote/deploy-cancellation-production.php' '$phase' '$run' '$remote/payload'" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

verify_staging_source() {
    python3 - "$work/bundle/payload/manifest.json" "$work/staging-command.private.sh" <<'PY'
import json,re,sys
from pathlib import Path
manifest=json.loads(Path(sys.argv[1]).read_text())
if len(manifest)!=27 or any(not re.fullmatch(r'plugins/(?:petish-fulfilment|petish-passport)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|css|js|md)',name) for name in manifest):raise ValueError()
text='set -eu\nroot=/home/u839205597/domains/staging.petish.biz/public_html/wp-content\n'
text+='test -d "$root" && test ! -L "$root" && test "$(readlink -f -- "$root")" = "$root"\n'
text+='for relative in '+ ' '.join(sorted(manifest))+'; do\n'
text+='  file="$root/$relative"\n  test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %h "$file")" = 1\n  sha256sum "$file"\ndone\n'
Path(sys.argv[2]).write_text(text)
PY
    "${petish_ssh[@]}" 'bash -s' < "$work/staging-command.private.sh" \
        > "$work/staging-hashes.private.txt" 2> "$work/staging-hashes.private.stderr"
    python3 - "$work/bundle/payload/manifest.json" "$work/staging-hashes.private.txt" <<'PY' \
        > "$work/staging-source.private.stdout" 2> "$work/staging-source.private.stderr"
import json,re,sys
from pathlib import Path
expected=json.loads(Path(sys.argv[1]).read_text());actual={}
prefix='/home/u839205597/domains/staging.petish.biz/public_html/wp-content/'
lines=Path(sys.argv[2]).read_text().splitlines()
if len(lines)!=27:raise ValueError()
for line in lines:
 parts=line.split('  ',1)
 if len(parts)!=2 or not re.fullmatch(r'[a-f0-9]{64}',parts[0]) or not parts[1].startswith(prefix):raise ValueError()
 name=parts[1][len(prefix):]
 if name in actual:raise ValueError()
 actual[name]=parts[0]
if actual!=expected:raise ValueError()
PY
}

health_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in health|rollback-health) ;; *) return 1 ;; esac
    python3 scripts/sanitize-cancellation-production.py "probe-$phase" "$work/$phase.private.json" \
        > "$work/$phase-probe.private.stdout" 2> "$work/$phase-probe.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

purge_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in purge|rollback-purge) ;; *) return 1 ;; esac
    python3 scripts/sanitize-cancellation-production.py "probe-$phase" "$run" "$work/bundle/.probe-token" "$work/$phase.private.json" \
        > "$work/$phase-probe.private.stdout" 2> "$work/$phase-probe.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

collect_inspection() {
    "${petish_ssh[@]}" "set -eu; file='$remote/live-inspection.json'; test -f \"\$file\" && test ! -L \"\$file\" && test \"\$(stat -c %h \"\$file\")\" = 1 && test \"\$(stat -c %a \"\$file\")\" = 600; cat -- \"\$file\"" \
        > "$work/live-inspection.private.json" 2> "$work/live-inspection.private.stderr" || return 1
    python3 - "$work/live-inspection.private.json" "$work/bundle/payload/manifest.json" "$work/live-inspection.safe.json" "$run" <<'PY' \
        > "$work/live-inspection-screen.private.stdout" 2> "$work/live-inspection-screen.private.stderr"
import json,re,stat,sys
from pathlib import Path
path=Path(sys.argv[1]);info=path.lstat()
if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or not 0<info.st_size<=65536:raise ValueError()
def unique(pairs):
 value={}
 for key,item in pairs:
  if key in value:raise ValueError()
  value[key]=item
 return value
value=json.loads(path.read_text(),object_pairs_hook=unique)
expected=json.loads(Path(sys.argv[2]).read_text())
if set(value)!={'run','files'} or value['run']!=sys.argv[4] or not isinstance(value['files'],dict) or set(value['files'])!=set(expected) or len(expected)!=27:raise ValueError()
if any(item is not None and (not isinstance(item,str) or not re.fullmatch(r'[a-f0-9]{64}',item)) for item in value['files'].values()):raise ValueError()
with Path(sys.argv[3]).open('x') as stream:stream.write(json.dumps(value['files'],sort_keys=True)+'\n')
PY
}

remove_remote() {
    "${petish_ssh[@]}" 'set -eu; work='"'$remote'"';
      if test ! -e "$work" && test ! -L "$work"; then exit 0; fi;
      test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" && test "$(stat -c %a "$work")" = 700;
      marker="$work/.owned"; test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"'$run'"';
      unsafe=$(find "$work" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit); test -z "$unsafe";
      rm -rf -- "$work"' > "$work/remove.private.stdout" 2> "$work/remove.private.stderr"
}

publish_result() {
    export PC_PRODUCTION_WORK=$work PC_PRODUCTION_RUN=$run PC_PRODUCTION_SOURCE=$source_sha PC_PRODUCTION_MODE=$mode
    export PC_PRODUCTION_STATUS=$result_status PC_PRODUCTION_CATEGORY=$category PC_PRODUCTION_STAGE=$staging_source_verified
    export PC_PRODUCTION_CLEANED=$remote_tools_removed PC_PRODUCTION_ROLLBACK_ATTEMPTED=$rollback_attempted PC_PRODUCTION_ROLLBACK_VERIFIED=$rollback_verified
    python3 - <<'PY'
from datetime import datetime,timezone
import importlib.util,json,os,re,stat
from pathlib import Path
root=Path(os.environ['PC_PRODUCTION_WORK'])
categories={'prerequisites','source','evidence','transport','staging_source','inspect','apply','faq','verify','resume','health','commit','recovery_required','cleanup','complete'}
category=os.environ['PC_PRODUCTION_CATEGORY'];status=os.environ['PC_PRODUCTION_STATUS']
if category not in categories or status not in {'failure','in_progress','success'}:raise ValueError()
stage=os.environ.get('CANCELLATION_PRODUCTION_PREPARATION_STAGE','unknown')
value={'format':'petish-cancellation-production-v1','target':'production','scope':'cancellation-faq-review-layout',
 'mode':os.environ['PC_PRODUCTION_MODE'],'run_id':os.environ['PC_PRODUCTION_RUN'],'source_commit':os.environ['PC_PRODUCTION_SOURCE'],
 'generated_utc':datetime.now(timezone.utc).isoformat(),'status':status,'category':category,
 'preparation_stage':stage if stage in {'connection','ssh_tools','health_dependencies','complete'} else 'unknown',
 'staging_source_verified':os.environ['PC_PRODUCTION_STAGE']=='1','remote_tools_removed':os.environ['PC_PRODUCTION_CLEANED']=='1',
 'rollback_attempted':os.environ['PC_PRODUCTION_ROLLBACK_ATTEMPTED']=='1','rollback_verified':os.environ['PC_PRODUCTION_ROLLBACK_VERIFIED']=='1',
 'source_hashes':{},'live_source_hashes':{},'phases':{}}
spec=importlib.util.spec_from_file_location('cancellation_release_schema','scripts/sanitize-cancellation-production.py')
schema=importlib.util.module_from_spec(spec);spec.loader.exec_module(schema)
manifest=root/'bundle/payload/manifest.json'
if manifest.exists():
 value['source_hashes']=json.loads(schema.regular(manifest))
 if value['source_hashes']!=schema.release_files():raise ValueError()
observed=root/'live-inspection.safe.json'
if observed.exists():
 value['live_source_hashes']=json.loads(schema.regular(observed))
 if set(value['live_source_hashes'])!=set(schema.release_files()) or any(item is not None and (not isinstance(item,str) or not re.fullmatch(r'[a-f0-9]{64}',item)) for item in value['live_source_hashes'].values()):raise ValueError()
for phase in ['inspect','apply','faq','verify','resume','purge','health','commit','rollback','rollback-purge','rollback-health','rollback-finalize']:
 path=root/(phase+'.safe.json')
 if path.exists():value['phases'][phase]=schema.sanitize(phase,path)
proof=root/'release-evidence.json'
if proof.exists():
 evidence=json.loads(schema.regular(proof))
 if evidence.get('verified') is not True:raise ValueError()
 for key in ['staging_source_commit','staging_status_branch','staging_prepare_branch','staging_install_branch','backup_branch','backup_result_branch','recovery_result_branch']:
  item=evidence.get(key)
  pattern=r'[a-f0-9]{40}' if key=='staging_source_commit' else r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*' if key=='backup_branch' else r'automation-status/[a-z-]+-[1-9][0-9]*-[1-9][0-9]*'
  if not isinstance(item,str) or not re.fullmatch(pattern,item):raise ValueError()
  value[key]=item
(root/'status.json').write_text(json.dumps(value,sort_keys=True,indent=2)+'\n')
PY
    local blob tree commit
    blob=$(git hash-object -w "$work/status.json") || return 1
    tree=$(printf '100644 blob %s\tstatus.json\n' "$blob" | git mktree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    if [[ -n $published_commit ]]; then
        commit=$(printf 'Petish cancellation production result %s\n' "$run" | git commit-tree "$tree" -p "$published_commit") || return 1
    else
        commit=$(printf 'Petish cancellation production result %s\n' "$run" | git commit-tree "$tree") || return 1
    fi
    git push origin "$commit:refs/heads/automation-status/cancellation-production-$run" > "$work/push.private.stdout" 2> "$work/push.private.stderr" || return 1
    published_commit=$commit
    printf 'Screened cancellation release result: https://github.com/nkrake6/petish/tree/automation-status/cancellation-production-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then printf '[Cancellation production result](https://github.com/nkrake6/petish/tree/automation-status/cancellation-production-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"; fi
}

finish() {
    local outcome=$?
    trap - EXIT HUP INT TERM
    set +e
    if [[ $rollback_needed == 1 ]]; then
        rollback_attempted=1
        if remote_phase rollback; then
            if python3 - "$work/rollback.safe.json" <<'PY'
import json,sys
from pathlib import Path
raise SystemExit(0 if json.loads(Path(sys.argv[1]).read_text())['checks'].get('rollback_not_needed') is True else 1)
PY
            then rollback_needed=0; rollback_verified=1;
            elif purge_phase rollback-purge && health_phase rollback-health && remote_phase rollback-finalize; then
                rollback_needed=0; rollback_verified=1;
            else category=recovery_required; fi
        else category=recovery_required; fi
        outcome=1
    fi
    if [[ $ssh_ready == 1 && ! -f "$work/live-inspection.safe.json" ]]; then collect_inspection || true; fi
    if [[ $ssh_ready == 1 && $rollback_needed == 0 ]]; then
        if remove_remote; then remote_tools_removed=1; else category=cleanup; outcome=1; fi
    fi
    if [[ $outcome == 0 && $operation_verified == 1 && $remote_tools_removed == 1 ]]; then result_status=success; category=complete;
    else result_status=failure; outcome=1; fi
    if publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then cat "$work/publication.private.stdout";
    else printf '%s\n' 'Cancellation release receipt publication failed; private output was not published.' >&2; outcome=1; fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${CANCELLATION_PRODUCTION_PREREQUISITES:-failure} == success ]]
category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
python3 - "$source_sha" "$work/bundle/payload" <<'PY' > "$work/package.private.stdout" 2> "$work/package.private.stderr"
import hashlib,importlib.util,json,subprocess,sys
from pathlib import Path
def module(path,name):
 spec=importlib.util.spec_from_file_location(name,path);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
evidence=module('scripts/validate-cancellation-production.py','cancellation_evidence')
schema=module('scripts/sanitize-cancellation-production.py','cancellation_schema')
expected=schema.release_files()
if evidence.PREFIX!='wordpress/wp-content/' or len(evidence.FILES)!=27 or set(evidence.FILES)!=set(expected):raise ValueError()
destination=Path(sys.argv[2]);manifest={};total=0
for name in sorted(expected):
 path=evidence.PREFIX+name
 listing=subprocess.run(['git','ls-tree','-z',sys.argv[1],'--',path],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 entries=listing.rstrip(b'\0').split(b'\0')
 if len(entries)!=1 or b'\t' not in entries[0]:raise ValueError()
 header,actual=entries[0].split(b'\t',1);mode,kind,blob=header.decode('ascii').split(' ')
 if mode not in {'100644','100755'} or kind!='blob' or actual.decode('ascii')!=path:raise ValueError()
 size=int(subprocess.run(['git','cat-file','-s',blob],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout)
 if not 0<size<=1048576 or total+size>8388608:raise ValueError()
 content=subprocess.run(['git','cat-file','blob',blob],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 if len(content)!=size or hashlib.sha256(content).hexdigest()!=expected[name]:raise ValueError()
 total+=size;target=destination/name;target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 with target.open('xb') as stream:stream.write(content)
 target.chmod(0o600);manifest[name]=expected[name]
with (destination/'manifest.json').open('x') as stream:stream.write(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
(destination/'manifest.json').chmod(0o600)
PY
if [[ $mode == apply ]]; then
    category=evidence
    python3 scripts/validate-cancellation-production.py operations/cancellation-production-release.json "$work/release-evidence.json" \
        > "$work/evidence.private.stdout" 2> "$work/evidence.private.stderr"
fi
python3 - "$work/bundle/.probe-token" <<'PY'
import secrets,sys
from pathlib import Path
with Path(sys.argv[1]).open('x') as stream:stream.write(secrets.token_hex(32)+'\n')
Path(sys.argv[1]).chmod(0o600)
PY
for name in deploy-cancellation-production.php cancellation-production-cache.php verify-pet-club.php email-branding-preview-remote.php verify-cancellation-requests.php review-dashboard-spacing-release.php; do
    git show "$source_sha:scripts/$name" > "$work/bundle/$name"
    chmod 600 "$work/bundle/$name"
done
git show "$source_sha:operations/delivery-page-content.html" > "$work/bundle/delivery-page-content.html"
chmod 600 "$work/bundle/delivery-page-content.html"
(cd "$work/bundle" && sha256sum deploy-cancellation-production.php cancellation-production-cache.php verify-pet-club.php email-branding-preview-remote.php verify-cancellation-requests.php review-dashboard-spacing-release.php delivery-page-content.html .probe-token payload/manifest.json > tools.sha256)
chmod 600 "$work/bundle/tools.sha256"
tar -C "$work/bundle" -cf "$work/bundle.tar" deploy-cancellation-production.php cancellation-production-cache.php verify-pet-club.php email-branding-preview-remote.php verify-cancellation-requests.php review-dashboard-spacing-release.php delivery-page-content.html .probe-token tools.sha256 payload \
    > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh > "$work/ssh.private.stdout" 2> "$work/ssh.private.stderr"
ssh_ready=1
"${petish_ssh[@]}" 'set -eu; umask 077; work='"'$remote'"';
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$work" && test ! -L "$work";
  mkdir -m 700 "$work"; printf "%s\n" '"'$run'"' > "$work/.owned"; chmod 600 "$work/.owned";
  tar --extract --no-same-owner --no-same-permissions --directory "$work";
  cd "$work"; sha256sum --check --strict tools.sha256' \
    < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
if [[ $mode == apply ]]; then
    category=staging_source
    verify_staging_source
    staging_source_verified=1
fi
category=inspect
remote_phase inspect
collect_inspection
result_status=in_progress
publish_result > "$work/progress.private.stdout" 2> "$work/progress.private.stderr"
if [[ $mode == inspect ]]; then operation_verified=1; exit 0; fi
category=apply
rollback_needed=1
remote_phase apply
category=faq
remote_phase faq
category=verify
remote_phase verify
category=resume
remote_phase resume
category=health
purge_phase purge
health_phase health
category=commit
remote_phase commit
rollback_needed=0
operation_verified=1

const assert = require('assert');
const fs = require('fs');

const workflow = fs.readFileSync('.github/workflows/docker-publish.yml', 'utf8');

const phpSetup = workflow.indexOf('- name: Set up PHP test runtime');
const cacheResolve = workflow.indexOf('- name: Resolve Composer cache directory');
const cacheUse = workflow.indexOf('- name: Cache Composer packages');
const install = workflow.indexOf('- name: Install PHP test dependencies');

assert(phpSetup >= 0 && phpSetup < cacheResolve && cacheResolve < cacheUse && cacheUse < install,
  'Composer cache discovery must run after PHP setup and before dependency installation');
assert(workflow.includes('composer config cache-files-dir'),
  'Composer cache path must come from the installed Composer runtime');
assert(workflow.includes('path: ${{ steps.composer-cache.outputs.dir }}'),
  'actions/cache must use the resolved Composer files cache directory');
assert(!workflow.includes('path: ~/.composer/cache/files'),
  'workflow still uses the stale Composer cache path that GitHub cannot save');
assert(workflow.includes('cache-from: type=gha') && workflow.includes('cache-to: type=gha,mode=max'),
  'GitHub Actions BuildKit cache must remain enabled');

const candidateMetadata = workflow.indexOf('- name: Extract unique smoke-candidate metadata');
const releaseMetadata = workflow.indexOf('- name: Extract tested release tags');
const buildAndPush = workflow.indexOf('- name: Build and push unique smoke candidate');
const smoke = workflow.indexOf('- name: Smoke test published image');
const publishReleaseTags = workflow.indexOf('- name: Publish tested release tags');
assert(candidateMetadata >= 0 && candidateMetadata < releaseMetadata && releaseMetadata < buildAndPush
  && buildAndPush < smoke && smoke < publishReleaseTags,
  'release tags must be published only after a unique candidate passes smoke tests');
const candidateBlock = workflow.slice(candidateMetadata, releaseMetadata);
assert(candidateBlock.includes('candidate-${{ github.run_id }}-${{ github.run_attempt }}')
  && !candidateBlock.includes('type=sha') && !candidateBlock.includes('type=ref,event=branch')
  && !candidateBlock.includes('value=latest') && !candidateBlock.includes('value=new'),
  'the pre-smoke build must publish only a run-attempt-specific candidate tag');
assert(workflow.includes('steps.build-and-push.outputs.digest')
  && workflow.includes("jq -er '.digest'")
  && workflow.includes('test "$SMOKED_DIGEST" = "$IMAGE_DIGEST"')
  && workflow.includes('docker buildx imagetools create')
  && workflow.includes('"${IMAGE_REF}@${IMAGE_DIGEST}"'),
  'post-smoke release tags must point at the exact multi-platform digest that was tested');
assert(workflow.includes("expected_release_tag_count=4")
  && workflow.includes("expected_release_tag_count=6")
  && workflow.includes('test "$release_tag_count" -eq "$expected_release_tag_count"'),
  'release promotion must require exactly four branch tags or six master tags');

console.log('GitHub Actions caches are correct and every release tag publishes only after its unique candidate passes smoke.');

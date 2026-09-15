const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

for (const file of ['index_superadmin.php', 'pages/pendenzen.php']) {
  for (const cancel of [false, true]) {
    test(`${file}: ${cancel ? 'cancel releases microphone without upload' : 'upload includes delayed final audio and actual MIME'}`, async () => {
      const source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
      const dashboard = file === 'index_superadmin.php';
      const start = source.indexOf(dashboard ? 'async function stopVoiceRecording' : 'const stopVoiceRecording =');
      const end = source.indexOf(dashboard ? 'function toggleVoiceRecording' : 'const toggleVoiceRecording', start);
      const code = source.slice(start, end);
      const chunks = [];
      let stopped = false;
      let uploaded;
      let uploadedBlob;
      const recorder = {
        state: 'recording', mimeType: 'audio/ogg',
        addEventListener(name, fn) { this['on' + name] = fn; },
        stop() {
          this.state = 'inactive';
          setTimeout(() => {
            chunks.push(new Blob(['final audio'], { type: this.mimeType }));
            this.onstop?.();
          }, 5);
        }
      };
      const stream = { getTracks: () => [{ stop() { stopped = true; } }] };
      const element = { value: '', style: {}, classList: { remove() {} } };
      const context = vm.createContext({
        Blob, console, clearInterval, setTimeout, clearTimeout,
        document: { getElementById: () => element },
        window: { location: { pathname: '/index_superadmin.php' } },
        voiceIsListening: true, isRecording: true,
        voiceStopping: false, voiceStarting: false,
        voiceRecordingTimer: null, recordTimer: null,
        voiceRecognition: null, recognition: null,
        voiceMediaRecorder: recorder, mediaRecorder: recorder,
        voiceMediaStream: stream, mediaStream: stream,
        voiceAudioChunks: chunks, audioChunks: chunks,
        voiceMicCircle: element, voiceStatusText: element, voiceTranscriptInput: element,
        renderParsedVoiceBadges() {}, renderParsedBadges() {},
        MediaRecorder: { isTypeSupported: () => false },
        FileReader: class {
          readAsDataURL(blob) {
            uploadedBlob = blob;
            this.result = 'data:audio/ogg;base64,ZmluYWwgYXVkaW8=';
            this.onloadend();
          }
        },
        fetch: async (url, options) => {
          uploaded = JSON.parse(options.body);
          return { ok: true, status: 200, redirected: false,
            headers: { get: () => 'application/json' },
            json: async () => ({ ok: true, parsed: { beschreibung: 'Test' } }) };
        }
      });
      vm.runInContext(code + '\nglobalThis.runStop = stopVoiceRecording;', context);
      await context.runStop(!cancel);
      await new Promise(resolve => setTimeout(resolve, 20));
      assert.equal(stopped, true);
      if (cancel) assert.equal(uploaded, undefined);
      else {
        assert.ok(uploaded, 'Final audio must be uploaded even without earlier chunks');
        assert.equal(await uploadedBlob.text(), 'final audio');
        assert.equal(uploaded.audio_mime, 'audio/ogg');
      }
    });
  }
}

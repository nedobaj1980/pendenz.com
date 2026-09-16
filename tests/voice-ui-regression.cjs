const fs = require('fs');
const source = fs.readFileSync('pages/pendenzen.php', 'utf8');

if (!source.includes("new URL('../api/voice_pendenz.php', window.location.href).href")) {
  throw new Error('Voice UI must resolve the API URL from the current document URL');
}
if (source.includes('lastParsedData = null;\n                startVoiceRecording();')) {
  throw new Error('Opening the voice modal must not request the microphone automatically');
}
if (!source.includes('const getSupportedRecordingMime = () =>')) {
  throw new Error('Voice UI must choose a browser-supported recording MIME type');
}
if (source.includes('mediaRecorder.start(500)')) {
  throw new Error('Voice recorder must avoid Safari-incompatible timeslice mode');
}
if (!source.includes('recorderToStop.onstop = () =>')) {
  throw new Error('Voice recorder must process Safari data after onstop');
}
if (source.includes('1-Klick Smartphone Tastatur-Diktat Banner')) {
  throw new Error('The redundant keyboard-dictation banner must be removed');
}
if (!source.includes('const isIOSSafari =')) {
  throw new Error('Voice button must use iPhone keyboard dictation on iOS Safari');
}
if (!source.includes('const focusKeyboardDictation = () =>')) {
  throw new Error('iOS Safari voice button must focus the textarea for system dictation');
}
console.log('OK');

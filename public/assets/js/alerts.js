/**
 * EleGuard Rail Train Driver Alert Sounds
 * Uses the Web Audio API to trigger safety alarms when Critical alerts are visible on the dashboard.
 */

let audioCtx = null;
let soundInterval = null;
let isMuted = localStorage.getItem('driver_audio_muted') === 'true';

// Initialize audio context safely after user interaction
function initAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
}

// Generate a dual-frequency alarm beep tone using Web Audio oscillators
function playEmergencyBeep() {
    if (isMuted) return;
    initAudio();
    
    if (!audioCtx) return;

    try {
        const osc1 = audioCtx.createOscillator();
        const osc2 = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();

        osc1.type = 'sawtooth';
        osc1.frequency.setValueAtTime(880, audioCtx.currentTime); // High pitch frequency A5
        
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(660, audioCtx.currentTime); // Complementary frequency E5

        gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.2, audioCtx.currentTime + 0.05); // Attack
        gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.45); // Release

        osc1.connect(gainNode);
        osc2.connect(gainNode);
        gainNode.connect(audioCtx.destination);

        osc1.start();
        osc2.start();
        
        osc1.stop(audioCtx.currentTime + 0.5);
        osc2.stop(audioCtx.currentTime + 0.5);
    } catch (e) {
        console.error("Audio trigger failed:", e);
    }
}

// Monitor warning screen for active critical alert divs
function checkAlertsForSound() {
    const criticalAlerts = document.querySelectorAll('.driver-alert-critical');
    const audioBtn = document.getElementById('toggleAudioBtn');
    const audioStatus = document.getElementById('audioStatusText');

    if (criticalAlerts.length > 0 && !isMuted) {
        if (!soundInterval) {
            // Play initial alarm
            playEmergencyBeep();
            // Setup repeating alarm siren (every 1.5 seconds)
            soundInterval = setInterval(playEmergencyBeep, 1500);
            
            if (audioStatus) {
                audioStatus.textContent = "🔊 ALARM ACTIVE";
                audioStatus.style.color = "var(--danger)";
            }
        }
    } else {
        stopAlarm();
        if (audioStatus) {
            if (isMuted) {
                audioStatus.textContent = "🔇 MUTED";
                audioStatus.style.color = "var(--text-muted)";
            } else {
                audioStatus.textContent = "🔊 MONITORING ACTIVE";
                audioStatus.style.color = "var(--success)";
            }
        }
    }
}

function stopAlarm() {
    if (soundInterval) {
        clearInterval(soundInterval);
        soundInterval = null;
    }
}

function toggleAudio() {
    isMuted = !isMuted;
    localStorage.setItem('driver_audio_muted', isMuted ? 'true' : 'false');
    
    const audioBtn = document.getElementById('toggleAudioBtn');
    
    if (isMuted) {
        stopAlarm();
        if (audioBtn) {
            audioBtn.textContent = "Unmute Warning Alarms";
            audioBtn.classList.add('muted');
        }
    } else {
        initAudio();
        if (audioBtn) {
            audioBtn.textContent = "Mute Warning Alarms";
            audioBtn.classList.remove('muted');
        }
        playEmergencyBeep();
    }
    
    checkAlertsForSound();
}

document.addEventListener('DOMContentLoaded', () => {
    const audioBtn = document.getElementById('toggleAudioBtn');
    
    if (audioBtn) {
        // Restore saved muted state UI
        if (isMuted) {
            audioBtn.textContent = "Unmute Warning Alarms";
            audioBtn.classList.add('muted');
        } else {
            audioBtn.textContent = "Mute Warning Alarms";
            audioBtn.classList.remove('muted');
        }
        
        audioBtn.addEventListener('click', () => {
            initAudio();
            toggleAudio();
        });
    }

    // Periodically run check
    checkAlertsForSound();
    setInterval(checkAlertsForSound, 2000);
});

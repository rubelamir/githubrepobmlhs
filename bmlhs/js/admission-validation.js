(() => {
  const fields = document.querySelectorAll('form .input');
  if (!fields.length) {
    return;
  }

  const updateId = document.getElementById('update_id')?.value || '';
  let tokenTimer = null;
  let tokenXhr = null;
  let birthTimer = null;
  let birthXhr = null;
  let mobileTimer = null;
  let mobileXhr = null;

  const englishPattern = /^[a-zA-Z-'. ]*$/;
  const bengaliBlocked = /^[a-zA-Z0-9 .]*$/;
  const mobilePattern = /^[0০][1১][0-9০-৯]{9}$/;

  function feedbackEl(input) {
    const next = input.nextElementSibling;
    if (next && next.tagName === 'DIV') {
      return next;
    }
    return input.parentElement?.querySelector('.invalid-feedback, .valid-feedback') || null;
  }

  function setErrorMsg(input, message) {
    input.classList.remove('is-valid');
    input.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
    const msg = feedbackEl(input);
    if (msg) {
      msg.classList.add('invalid-feedback');
      msg.classList.remove('valid-feedback', 'd-none');
      msg.textContent = message;
    }
  }

  function setSuccessMsg(input) {
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    input.setAttribute('aria-invalid', 'false');
    const msg = feedbackEl(input);
    if (msg) {
      msg.classList.add('invalid-feedback');
      msg.textContent = '';
    }
  }

  function clearFieldState(input) {
    input.classList.remove('is-valid', 'is-invalid');
    input.removeAttribute('aria-invalid');
    const msg = feedbackEl(input);
    if (msg) {
      msg.textContent = '';
    }
  }

  function abortRequest(xhr) {
    if (xhr && xhr.readyState !== 4) {
      xhr.abort();
    }
  }

  function ajaxPost(data, onSuccess) {
    return $.ajax({
      type: 'POST',
      url: 'ajax_request.php',
      data,
      success(response) {
        onSuccess(String(response || '').trim());
      },
      error(xhr) {
        if (xhr && xhr.statusText === 'abort') {
          return;
        }
      },
    });
  }

  function checkToken(element, fromBlur) {
    const value = String(element.value || '').trim();
    if (tokenTimer) {
      clearTimeout(tokenTimer);
      tokenTimer = null;
    }
    abortRequest(tokenXhr);
    tokenXhr = null;
    if (value === '') {
      setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      return;
    }
    if (value.length < 4) {
      if (fromBlur) {
        setErrorMsg(element, 'টোকেনটি সঠিক নয়');
      } else {
        clearFieldState(element);
      }
      return;
    }
    element.classList.remove('is-valid', 'is-invalid');
    tokenTimer = setTimeout(() => {
      tokenXhr = ajaxPost({ admission_tokens_input: value }, (response) => {
        if (String(element.value || '').trim() !== value) {
          return;
        }
        if (response === 'admission_token_error_1') {
          setErrorMsg(element, 'এই টোকেনটি ইতিমধ্যেই ব্যবহৃত হয়েছে');
        } else if (response === 'admission_token_error_2') {
          setErrorMsg(element, 'টোকেনটি সঠিক নয়');
        } else if (response === 'admission_token_success') {
          setSuccessMsg(element);
        }
      });
    }, 280);
  }

  function checkBirthReg(element) {
    const value = String(element.value || '').trim();
    if (birthTimer) {
      clearTimeout(birthTimer);
      birthTimer = null;
    }
    abortRequest(birthXhr);
    birthXhr = null;
    if (value === '') {
      setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      return;
    }
    if (value.length !== 17) {
      setErrorMsg(element, `জন্ম নিবন্ধন নাম্বার 17 সংখ্যার হয়। আপনি লিখেছেন ${value.length} সংখ্যা`);
      return;
    }
    element.classList.remove('is-valid', 'is-invalid');
    const data = { birth_reg_no_input: value };
    if (updateId !== '') {
      data.update_id = updateId;
    }
    birthTimer = setTimeout(() => {
      birthXhr = ajaxPost(data, (response) => {
        if (String(element.value || '').trim() !== value) {
          return;
        }
        if (response === 'birth_reg_no_already_taken_error') {
          setErrorMsg(element, 'ইতি পূর্বে এই জন্ম নিবন্ধন নাম্বার ব্যবহৃত হয়েছে');
        } else if (response === 'birth_reg_no_success') {
          setSuccessMsg(element);
        }
      });
    }, 280);
  }

  function checkMobile(element) {
    const value = String(element.value || '').trim();
    if (mobileTimer) {
      clearTimeout(mobileTimer);
      mobileTimer = null;
    }
    abortRequest(mobileXhr);
    mobileXhr = null;
    if (value === '') {
      setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      return;
    }
    if (!mobilePattern.test(value)) {
      setErrorMsg(element, 'সঠিক মোবাইল নাম্বার লিখুন');
      return;
    }
    element.classList.remove('is-valid', 'is-invalid');
    const data = { mobile_input: value };
    if (updateId !== '') {
      data.update_id = updateId;
    }
    mobileTimer = setTimeout(() => {
      mobileXhr = ajaxPost(data, (response) => {
        if (String(element.value || '').trim() !== value) {
          return;
        }
        if (response === 'mobile_already_taken_error') {
          setErrorMsg(element, 'ইতি পূর্বে এই মোবাইল নাম্বার ব্যবহৃত হয়েছে');
        } else if (response === 'mobile_success') {
          setSuccessMsg(element);
        }
      });
    }, 280);
  }

  function checkPassword(element) {
    const value = element.value;
    if (value === '') {
      setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      return;
    }
    if (value.length < 5) {
      setErrorMsg(element, 'পাসওয়ার্ড কমপক্ষে ৫ অক্ষরের হবে');
      return;
    }
    setSuccessMsg(element);
    const confirm = document.getElementById('cpass');
    if (confirm && confirm.value !== '') {
      checkConfirmPassword(confirm);
    }
  }

  function checkConfirmPassword(element) {
    const pass = document.getElementById('pass')?.value || '';
    const value = element.value;
    if (value === '') {
      setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      return;
    }
    if (pass !== value) {
      setErrorMsg(element, 'পাসওয়ার্ড এবং কনফার্ম পাসওয়ার্ড ম্যাচিং হয়নি');
      return;
    }
    setSuccessMsg(element);
  }

  function checkNid(element) {
    const value = String(element.value || '').trim();
    if (value === '') {
      clearFieldState(element);
      return;
    }
    if (value.length !== 10 && value.length !== 13 && value.length !== 17) {
      setErrorMsg(element, `জাতীয় পরিচয় পত্র নাম্বার 10/13/17 সংখ্যার হয়। আপনি লিখেছেন ${value.length} সংখ্যা`);
      return;
    }
    setSuccessMsg(element);
  }

  function checkBengali(element, required) {
    const value = String(element.value || '').trim();
    if (value === '') {
      if (required) {
        setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      } else {
        clearFieldState(element);
      }
      return;
    }
    if (bengaliBlocked.test(value)) {
      setErrorMsg(element, 'বাংলায় লিখুন');
      return;
    }
    setSuccessMsg(element);
  }

  function checkEnglish(element, required) {
    const value = String(element.value || '').trim();
    if (value === '') {
      if (required) {
        setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      } else {
        clearFieldState(element);
      }
      return;
    }
    if (!englishPattern.test(value)) {
      setErrorMsg(element, 'Only English letters, dot & white space allowed');
      return;
    }
    setSuccessMsg(element);
  }

  function validateField(element, fromBlur) {
    const required = element.classList.contains('require');
    const value = String(element.value || '').trim();

    if (required && value === '') {
      if (fromBlur || element.dataset.filled === '1') {
        setErrorMsg(element, 'এই ফিল্ডটি অবশ্য পূরণীয়');
      } else {
        clearFieldState(element);
      }
      return;
    }

    if (value !== '') {
      element.dataset.filled = '1';
    }

    if (element.classList.contains('admission_token_input')) {
      checkToken(element, fromBlur);
      return;
    }
    if (element.classList.contains('english_input')) {
      checkEnglish(element, required);
      return;
    }
    if (element.classList.contains('bengali_input')) {
      checkBengali(element, required);
      return;
    }
    if (element.classList.contains('birth_reg_no_input')) {
      checkBirthReg(element);
      return;
    }
    if (element.classList.contains('mobile_input')) {
      checkMobile(element);
      return;
    }
    if (element.classList.contains('pass_input')) {
      checkPassword(element);
      return;
    }
    if (element.classList.contains('cpass_input')) {
      checkConfirmPassword(element);
      return;
    }
    if (element.classList.contains('nid_no_input')) {
      checkNid(element);
      return;
    }
    if (!required && value === '') {
      clearFieldState(element);
      return;
    }
    setSuccessMsg(element);
  }

  fields.forEach((element) => {
    element.addEventListener('input', () => validateField(element, false));
    element.addEventListener('change', () => validateField(element, true));
    element.addEventListener('blur', () => validateField(element, true));
    if (String(element.value || '').trim() !== '' && (element.classList.contains('is-valid') || element.classList.contains('is-invalid'))) {
      element.dataset.filled = '1';
    }
  });

  const rules = document.getElementById('rules');
  const submit = document.getElementById('admission_submit');
  if (rules && submit) {
    rules.addEventListener('change', () => {
      if (rules.checked) {
        rules.classList.remove('is-invalid');
        rules.classList.add('is-valid');
        submit.removeAttribute('disabled');
      } else {
        rules.classList.remove('is-valid');
        rules.classList.add('is-invalid');
        submit.setAttribute('disabled', 'disabled');
      }
    });
  }

  const otpSend = document.getElementById('admissionOtpSend');
  const otpIdInput = document.getElementById('mobile_otp_id');
  const otpCodeInput = document.getElementById('mobile_otp_code');
  const otpFeedback = document.getElementById('mobileOtpFeedback');
  const otpHint = document.getElementById('admissionOtpHint');
  const mobileInput = document.getElementById('mobile');
  let otpCooldownTimer = null;
  let otpCooldownUntil = 0;

  function csrfTokenValue() {
    const field = document.querySelector('#admissionForm input[name="csrf_token"]');
    if (field && field.value) {
      return field.value;
    }
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function setOtpMessage(message, isError) {
    if (otpFeedback) {
      otpFeedback.textContent = message || '';
      otpFeedback.classList.toggle('invalid-feedback', !!isError);
      otpFeedback.classList.toggle('d-block', !!message);
      otpFeedback.classList.toggle('text-success', !isError && !!message);
    }
    if (otpHint && message && !isError) {
      otpHint.textContent = message;
    }
  }

  function updateOtpSendState() {
    if (!otpSend || !mobileInput) {
      return;
    }
    const mobileOk = mobilePattern.test(String(mobileInput.value || '').trim())
      && !mobileInput.classList.contains('is-invalid');
    const cooling = Date.now() < otpCooldownUntil;
    otpSend.disabled = !mobileOk || cooling;
  }

  function startOtpCooldown(seconds) {
    otpCooldownUntil = Date.now() + seconds * 1000;
    if (otpCooldownTimer) {
      clearInterval(otpCooldownTimer);
    }
    const tick = () => {
      const left = Math.max(0, Math.ceil((otpCooldownUntil - Date.now()) / 1000));
      if (left <= 0) {
        clearInterval(otpCooldownTimer);
        otpCooldownTimer = null;
        otpSend.textContent = 'কোড পাঠান';
        updateOtpSendState();
        return;
      }
      otpSend.textContent = `আবার পাঠান (${left})`;
      updateOtpSendState();
    };
    tick();
    otpCooldownTimer = setInterval(tick, 250);
  }

  if (otpSend && mobileInput && otpIdInput && otpCodeInput) {
    mobileInput.addEventListener('input', () => {
      otpIdInput.value = '';
      updateOtpSendState();
    });
    mobileInput.addEventListener('blur', updateOtpSendState);
    updateOtpSendState();

    otpSend.addEventListener('click', () => {
      const mobile = String(mobileInput.value || '').trim();
      if (!mobilePattern.test(mobile)) {
        setErrorMsg(mobileInput, 'সঠিক মোবাইল নাম্বার লিখুন');
        updateOtpSendState();
        return;
      }
      otpSend.disabled = true;
      otpSend.textContent = 'পাঠানো হচ্ছে…';
      setOtpMessage('', false);
      $.ajax({
        type: 'POST',
        url: '/admission_otp.php',
        dataType: 'json',
        data: {
          action: 'send',
          mobile,
          csrf_token: csrfTokenValue(),
        },
      }).done((response) => {
        if (response && response.ok && response.otp_id) {
          otpIdInput.value = String(response.otp_id);
          otpCodeInput.value = '';
          otpCodeInput.focus();
          setOtpMessage(response.message || 'কোড পাঠানো হয়েছে।', false);
          startOtpCooldown(60);
          return;
        }
        setOtpMessage((response && response.error) || 'কোড পাঠানো যায়নি।', true);
        otpSend.textContent = 'কোড পাঠান';
        updateOtpSendState();
      }).fail((xhr) => {
        let message = 'কোড পাঠানো যায়নি।';
        try {
          const parsed = JSON.parse(xhr.responseText || '{}');
          if (parsed && parsed.error) {
            message = parsed.error;
          }
        } catch (ignore) {
          // keep default
        }
        setOtpMessage(message, true);
        otpSend.textContent = 'কোড পাঠান';
        updateOtpSendState();
      });
    });
  }
})();

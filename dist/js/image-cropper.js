(() => {
  const compressedCrop = (sourceCanvas, maxWidth, maxHeight, maxLength) => {
    let width = sourceCanvas.width;
    let height = sourceCanvas.height;
    const scale = Math.min(1, maxWidth / width, maxHeight / height);
    width = Math.max(1, Math.round(width * scale));
    height = Math.max(1, Math.round(height * scale));
    const canvas = document.createElement('canvas');
    let dataUrl = '';
    for (let attempt = 0; attempt < 8; attempt += 1) {
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d').drawImage(sourceCanvas, 0, 0, width, height);
      let quality = 0.9;
      dataUrl = canvas.toDataURL('image/jpeg', quality);
      while (dataUrl.length > maxLength && quality > 0.45) {
        quality -= 0.05;
        dataUrl = canvas.toDataURL('image/jpeg', quality);
      }
      if (dataUrl.length <= maxLength) {
        return dataUrl;
      }
      width = Math.max(1, Math.round(width * 0.8));
      height = Math.max(1, Math.round(height * 0.8));
    }
    return dataUrl;
  };

  const bindField = (field) => {
    const input = document.querySelector(field.input);
    const hidden = document.querySelector(field.hidden);
    const image = document.querySelector(field.image);
    const preview = document.querySelector(field.preview);
    if (!input || !hidden || !image || !preview) {
      return null;
    }
    let cropper = null;
    const options = {
      viewMode: 1,
      autoCropArea: 1,
    };
    if (field.aspectRatio) {
      options.aspectRatio = field.aspectRatio;
    }
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) {
        return;
      }
      if (!file.type.startsWith('image/')) {
        input.setCustomValidity('Please choose an image file.');
        input.reportValidity();
        return;
      }
      input.setCustomValidity('');
      const reader = new FileReader();
      reader.onload = () => {
        image.src = reader.result;
        preview.style.display = 'block';
        if (cropper) {
          cropper.destroy();
        }
        cropper = new Cropper(image, options);
      };
      reader.readAsDataURL(file);
    });
    return {
      cropper: () => cropper,
      apply() {
        if (!cropper) {
          return true;
        }
        const canvasOptions = { imageSmoothingQuality: 'high' };
        if (field.width && field.height) {
          canvasOptions.width = field.width;
          canvasOptions.height = field.height;
        }
        const canvas = cropper.getCroppedCanvas(canvasOptions);
        const data = compressedCrop(
          canvas,
          field.maxWidth || field.width || 1600,
          field.maxHeight || field.height || 1600,
          field.maxLength
        );
        if (data.length > field.maxLength) {
          input.setCustomValidity(field.errorMessage || 'Cropped image is too large.');
          input.reportValidity();
          return false;
        }
        input.setCustomValidity('');
        hidden.value = data;
        return true;
      },
    };
  };

  window.AdminImageCropper = {
    bindForm(formSelector, fields) {
      const form = document.querySelector(formSelector);
      if (!form) {
        return;
      }
      const binders = fields.map(bindField).filter(Boolean);
      form.addEventListener('submit', (event) => {
        for (const binder of binders) {
          if (!binder.apply()) {
            event.preventDefault();
            return;
          }
        }
      });
    },
  };
})();

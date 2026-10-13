<script>


if (typeof Fancybox !== 'undefined' && document.querySelector('[data-fancybox]')) {

Fancybox.bind('[data-fancybox="gallery"]', {
  Image: {
    zoom: false,
  },
});
// Plain data-fancybox elements (portraits, single images) need their own binding.
Fancybox.bind('[data-fancybox]:not([data-fancybox="gallery"])');
}
// Table Loading function
// common functions
var form_field = document.querySelectorAll(".form_field");

const isRequired = value => value === '' ? false : true;

const isBetween = (length, min, max) => length < min || length > max ? false : true;

const showError = (input, message) => {
// get the form-field element
const formField = input.parentElement;
// add the error class
formField.classList.remove('success');
formField.classList.add('error');
// show the error message
const error = formField.querySelector('small');
error.textContent = message;
};

const showSuccess = (input) => {
// get the form-field element
const formField = input.parentElement;
// remove the error class
formField.classList.remove('error');
formField.classList.add('success');
// hide the error message
const error = formField.querySelector('small');
error.textContent = '';
}

const text_field_check_func = (text, min, max) => {
let valid = false;
const text_value = text.value.trim();
if (!isRequired(text_value)) {
showError(text, 'This field cannot be blank.');
} else if (!isBetween(text_value.length, min, max)) {
showError(text, `This field must be between ${min} and ${max} characters.`)
} else {
showSuccess(text);
valid = true;
}
return valid;
};

const text_field_check_non_require_func = (text) => {
  showSuccess(text);
return true;
};

const select_option_check_func = (select_id) => {
let valid = false;
const select_id_value = select_id.value.trim();
if (!isRequired(select_id_value)) {
showError(select_id, 'This field cannot be blank.');
}else {
showSuccess(select_id);
valid = true;
}
return valid;
};


const mobile_number_check_func = (mobile_id) => {
var reg_exp = /^[0০]{1}[1১]{1}[0-9০-৯]{9}$/;
let valid = false;
const mobile_number = mobile_id.value.trim();
if (!isRequired(mobile_number)) {
showError(mobile_id, 'This field cannot be blank.');
} else if (!reg_exp.test(mobile_number)) {
showError(mobile_id, 'Not a valid phone number');
}else {
showSuccess(mobile_id);
valid = true;
}
return valid;
};

const file_check_func = (file_id, file_type, file_size, file_type_error_msg) => {
let valid = false;
let file_id_value = file_id.value.trim();
file_id_value = file_id_value.toLowerCase();
if (isRequired(file_id_value)) {
var extdot = file_id_value.lastIndexOf(".");
var ext = file_id_value.substr(extdot, file_id_value.length);
if (file_type.includes(ext)) {
if (file_id.files[0].size / 1024 > file_size) {
Swal.fire("File size must be smaller than" +  Math.ceil(file_size) + "Kb, Current Size: " + Math.ceil(file_id.files[0].size /
1024) + " kb.");
file_id.value = "";
showError(file_id, 'file is too big!!!.');
} else {
showSuccess(file_id);
valid = true;
}
} else {
Swal.fire(file_type_error_msg);
file_id.value = "";
showError(file_id, file_type_error_msg);
}
} else {
showError(file_id, 'File cannot be blank.');
}
return valid;
};

const debounce = (fn, delay = 300) => {
let timeoutId;
return (...args) => {
// cancel the previous timer
if (timeoutId) {
clearTimeout(timeoutId);
}
// setup a new timer
timeoutId = setTimeout(() => {
fn.apply(null, args)
}, delay);
};
};
</script>
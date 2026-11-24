// Проверка форм на клиенте. Правила и тексты сообщений совпадают с серверными.
// Каждая функция принимает значения формы и возвращает объект { поле: сообщение } — по одной ошибке на поле.
import { pluralize } from './format.js';

const CHARACTERS = ['символ', 'символа', 'символов'];

/**
 * Длина строки в символах после обрезки пробелов (как на сервере).
 *
 * @param {string} value Строка
 * @returns {number}
 */
function lengthOf(value) {
	return [...String(value ?? '').trim()].length;
}

/**
 * Пустое ли значение: пустая строка, null или undefined.
 *
 * @param {unknown} value Значение поля
 * @returns {boolean}
 */
function isEmpty(value) {
	return value === null || value === undefined || String(value).trim() === '';
}

/**
 * Обязательное поле.
 *
 * @param {unknown} value Значение
 * @returns {string} Сообщение или пустая строка
 */
function required(value) {
	return isEmpty(value) ? 'Обязательное поле' : '';
}

/**
 * Допустимая длина.
 *
 * @param {string} value Значение
 * @param {number} min Минимум символов
 * @param {number} max Максимум символов
 * @returns {string} Сообщение или пустая строка
 */
function lengthBetween(value, min, max) {
	const length = lengthOf(value);
	if (length < min) return `Минимум ${min} ${pluralize(min, CHARACTERS)}`;
	if (length > max) return `Максимум ${max} ${pluralize(max, CHARACTERS)}`;
	return '';
}

/**
 * Возвращает первое непустое сообщение из списка проверок.
 *
 * @param {...string} messages Результаты проверок по порядку
 * @returns {string}
 */
function firstError(...messages) {
	return messages.find((message) => message !== '') ?? '';
}

/**
 * Убирает из объекта поля без ошибок.
 *
 * @param {Record<string, string>} errors Сообщения по полям (пустая строка — ошибки нет)
 * @returns {Record<string, string>}
 */
function onlyErrors(errors) {
	return Object.fromEntries(Object.entries(errors).filter(([, message]) => message !== ''));
}

/** @param {string} value */
function usernameError(value) {
	return firstError(
		required(value),
		lengthBetween(value, 3, 50),
		/^[A-Za-z0-9_]+$/.test(value.trim()) ? '' : 'Только латинские буквы, цифры и знак _'
	);
}

/** @param {string} value */
function emailError(value) {
	return firstError(
		required(value),
		lengthBetween(value, 1, 255),
		/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim()) ? '' : 'Введите корректный email'
	);
}

/** @param {string} value */
function passwordError(value) {
	const hasLetter = /\p{L}/u.test(value);
	const hasDigit = /\d/.test(value);
	return firstError(
		required(value),
		lengthBetween(value, 8, 72),
		hasLetter && hasDigit ? '' : 'Пароль должен содержать хотя бы одну букву и одну цифру'
	);
}

/**
 * @param {string} confirmation Подтверждение пароля
 * @param {string} password Пароль
 */
function confirmationError(confirmation, password) {
	return firstError(required(confirmation), confirmation === password ? '' : 'Пароли не совпадают');
}

/**
 * Регистрация.
 *
 * @param {{ username: string, email: string, password: string, password_confirmation: string }} values
 */
export function validateRegister(values) {
	return onlyErrors({
		username: usernameError(values.username),
		email: emailError(values.email),
		password: passwordError(values.password),
		password_confirmation: confirmationError(values.password_confirmation, values.password)
	});
}

/**
 * Создание пользователя администратором (подтверждение пароля не нужно).
 *
 * @param {{ username: string, email: string, password: string }} values
 */
export function validateNewUser(values) {
	return onlyErrors({
		username: usernameError(values.username),
		email: emailError(values.email),
		password: passwordError(values.password)
	});
}

/**
 * Вход.
 *
 * @param {{ login: string, password: string }} values
 */
export function validateLogin(values) {
	return onlyErrors({ login: required(values.login), password: required(values.password) });
}

/**
 * Запрос ссылки для сброса пароля.
 *
 * @param {{ email: string }} values
 */
export function validateForgotPassword(values) {
	return onlyErrors({ email: emailError(values.email) });
}

/**
 * Новый пароль.
 *
 * @param {{ password: string, password_confirmation: string }} values
 */
export function validateResetPassword(values) {
	return onlyErrors({
		password: passwordError(values.password),
		password_confirmation: confirmationError(values.password_confirmation, values.password)
	});
}

/**
 * Форма публикации (создание и редактирование).
 *
 * @param {{ title: string, text: string, category_id: number | string | null, type: string, allow_comments: boolean }} values
 */
export function validatePublication(values) {
	const types = ['question', 'discussion', 'announcement'];
	return onlyErrors({
		title: firstError(required(values.title), lengthBetween(values.title, 5, 255)),
		text: firstError(required(values.text), lengthBetween(values.text, 10, 10000)),
		category_id: isEmpty(values.category_id) ? 'Выберите раздел' : '',
		type: types.includes(values.type) ? '' : 'Выберите тип',
		allow_comments: typeof values.allow_comments === 'boolean' ? '' : 'Должно быть true или false'
	});
}

/**
 * Комментарий.
 *
 * @param {{ text: string }} values
 */
export function validateComment(values) {
	return onlyErrors({ text: firstError(required(values.text), lengthBetween(values.text, 1, 2000)) });
}

/**
 * Название раздела.
 *
 * @param {{ name: string }} values
 */
export function validateCategory(values) {
	return onlyErrors({ name: firstError(required(values.name), lengthBetween(values.name, 2, 100)) });
}

/**
 * Поиск: все поля необязательные, но если заполнены — должны быть корректны.
 *
 * @param {{ q: string, author: string, date_from: string, date_to: string }} values
 */
export function validateSearch(values) {
	const dateOrderError =
		values.date_from && values.date_to && values.date_to < values.date_from
			? 'Конечная дата не может быть раньше начальной'
			: '';
	return onlyErrors({
		q: isEmpty(values.q) ? '' : lengthBetween(values.q, 3, 100),
		author: isEmpty(values.author) ? '' : lengthBetween(values.author, 1, 255),
		date_to: dateOrderError
	});
}

/**
 * Поиск пользователей в админке.
 *
 * @param {{ q: string }} values
 */
export function validateUserSearch(values) {
	return onlyErrors({ q: isEmpty(values.q) ? '' : lengthBetween(values.q, 1, 100) });
}

<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Проверка полей запроса цепочкой правил.
 *
 * Для каждого поля сохраняется только первая найденная ошибка. Все правила,
 * кроме required(), пропускают пустые значения (null и ''), поэтому необязательные
 * поля проверяются только тогда, когда они переданы.
 */
class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data Проверяемые данные (тело запроса или query-параметры)
     */
    public function __construct(private array $data)
    {
    }

    /**
     * Поле должно быть передано и не пусто. Значение false и 0 считаются заполненными.
     *
     * @param string $field Имя поля
     * @return $this
     */
    public function required(string $field): self
    {
        if ($this->isEmpty($field)) {
            $this->fail($field, 'Обязательное поле');
        }

        return $this;
    }

    /**
     * Длина строки в символах должна быть в пределах [min, max].
     *
     * @param string $field Имя поля
     * @param int    $min   Минимальная длина
     * @param int    $max   Максимальная длина
     * @return $this
     */
    public function length(string $field, int $min, int $max): self
    {
        if (!$this->shouldCheck($field)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value)) {
            $this->fail($field, 'Должно быть текстом');
        } elseif (mb_strlen($value) < $min) {
            $this->fail($field, 'Минимум ' . $min . ' ' . $this->pluralizeChars($min));
        } elseif (mb_strlen($value) > $max) {
            $this->fail($field, 'Максимум ' . $max . ' ' . $this->pluralizeChars($max));
        }

        return $this;
    }

    /**
     * Значение должно входить в список допустимых.
     *
     * @param string            $field   Имя поля
     * @param array<int, mixed> $allowed Допустимые значения
     * @return $this
     */
    public function in(string $field, array $allowed): self
    {
        if ($this->shouldCheck($field) && !in_array($this->data[$field], $allowed, true)) {
            $this->fail($field, 'Недопустимое значение');
        }

        return $this;
    }

    /**
     * Значение должно быть корректным email-адресом.
     *
     * @param string $field Имя поля
     * @return $this
     */
    public function email(string $field): self
    {
        if ($this->shouldCheck($field)) {
            $value = $this->data[$field];

            if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $this->fail($field, 'Введите корректный email');
            }
        }

        return $this;
    }

    /**
     * Строка должна соответствовать регулярному выражению.
     *
     * @param string $field   Имя поля
     * @param string $pattern Регулярное выражение
     * @param string $message Сообщение об ошибке
     * @return $this
     */
    public function matches(string $field, string $pattern, string $message): self
    {
        if ($this->shouldCheck($field)) {
            $value = $this->data[$field];

            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                $this->fail($field, $message);
            }
        }

        return $this;
    }

    /**
     * Пароль: 8–72 символа (72 — предел алгоритма bcrypt), минимум одна буква и одна цифра.
     *
     * @param string $field Имя поля
     * @return $this
     */
    public function password(string $field): self
    {
        if (!$this->shouldCheck($field)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value)) {
            $this->fail($field, 'Должно быть текстом');
        } elseif (mb_strlen($value) < 8) {
            $this->fail($field, 'Минимум 8 символов');
        } elseif (strlen($value) > 72) {
            $this->fail($field, 'Максимум 72 символа');
        } elseif (preg_match('/\p{L}/u', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            $this->fail($field, 'Пароль должен содержать хотя бы одну букву и одну цифру');
        }

        return $this;
    }

    /**
     * Значение должно совпадать со значением другого поля.
     *
     * @param string $field   Имя проверяемого поля
     * @param string $other   Имя поля, с которым сравниваем
     * @param string $message Сообщение об ошибке
     * @return $this
     */
    public function same(string $field, string $other, string $message): self
    {
        if (!$this->hasError($field) && ($this->data[$field] ?? null) !== ($this->data[$other] ?? null)) {
            $this->fail($field, $message);
        }

        return $this;
    }

    /**
     * Значение должно быть строго логическим (true или false), а не строкой или числом.
     *
     * @param string $field Имя поля
     * @return $this
     */
    public function boolean(string $field): self
    {
        if ($this->shouldCheck($field) && !is_bool($this->data[$field])) {
            $this->fail($field, 'Должно быть true или false');
        }

        return $this;
    }

    /**
     * Значение должно быть целым положительным числом (допускается число или строка из цифр).
     *
     * @param string $field Имя поля
     * @param int    $min   Минимально допустимое значение
     * @param int    $max   Максимально допустимое значение
     * @return $this
     */
    public function integer(string $field, int $min = 1, int $max = PHP_INT_MAX): self
    {
        if (!$this->shouldCheck($field)) {
            return $this;
        }

        $value = $this->data[$field];
        $isWholeNumber = is_int($value) || (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1);

        if (!$isWholeNumber) {
            $this->fail($field, 'Должно быть целым числом');
        } elseif ((int) $value < $min || (int) $value > $max) {
            $this->fail($field, 'Допустимые значения: от ' . $min . ' до ' . $max);
        }

        return $this;
    }

    /**
     * Значение должно быть существующей датой в формате ГГГГ-ММ-ДД.
     *
     * @param string $field Имя поля
     * @return $this
     */
    public function date(string $field): self
    {
        if ($this->shouldCheck($field)) {
            $value = $this->data[$field];
            $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                $this->fail($field, 'Дата должна быть в формате ГГГГ-ММ-ДД');
            }
        }

        return $this;
    }

    /**
     * Дата в поле не должна быть раньше даты в другом поле (формат ГГГГ-ММ-ДД).
     *
     * @param string $field   Имя проверяемой даты
     * @param string $other   Имя поля с более ранней границей
     * @param string $message Сообщение об ошибке
     * @return $this
     */
    public function notBefore(string $field, string $other, string $message): self
    {
        if (
            $this->shouldCheck($field)
            && !$this->hasError($other)
            && !$this->isEmpty($other)
            && $this->data[$field] < $this->data[$other]
        ) {
            $this->fail($field, $message);
        }

        return $this;
    }

    /**
     * Произвольная проверка, например уникальность в БД. Функция вызывается, только если
     * поле заполнено и других ошибок у него ещё нет.
     *
     * @param string                $field   Имя поля
     * @param callable(mixed): bool $isValid Принимает значение поля, возвращает true, если оно допустимо
     * @param string                $message Сообщение об ошибке
     * @return $this
     */
    public function check(string $field, callable $isValid, string $message): self
    {
        if ($this->shouldCheck($field) && !$isValid($this->data[$field])) {
            $this->fail($field, $message);
        }

        return $this;
    }

    /**
     * Параметры страницы: page ≥ 1 и per_page от 1 до 50.
     *
     * @return $this
     */
    public function pagination(): self
    {
        return $this->integer('page')->integer('per_page', 1, 50);
    }

    /**
     * Общие правила новой учётной записи (регистрация и создание пользователя админом):
     * username, email и password, включая уникальность username и email.
     *
     * @return $this
     */
    public function newAccount(): self
    {
        return $this
            ->required('username')
            ->length('username', 3, 50)
            ->matches('username', '/^[A-Za-z0-9_]+$/', 'Только латинские буквы, цифры и знак _')
            ->check('username', fn (string $value): bool => !User::usernameExists($value), 'Это имя уже занято')
            ->required('email')
            ->length('email', 1, 255)
            ->email('email')
            ->check('email', fn (string $value): bool => !User::emailExists($value), 'Этот email уже зарегистрирован')
            ->required('password')
            ->password('password');
    }

    /**
     * Есть ли хотя бы одна ошибка.
     *
     * @return bool
     */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Ошибки по полям.
     *
     * @return array<string, string> Ключ — имя поля, значение — сообщение
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Есть ли уже ошибка у поля.
     *
     * @param string $field Имя поля
     * @return bool
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * Нужно ли применять правило: поле заполнено и ошибок у него ещё нет.
     *
     * @param string $field Имя поля
     * @return bool
     */
    private function shouldCheck(string $field): bool
    {
        return !$this->hasError($field) && !$this->isEmpty($field);
    }

    /**
     * Поле не передано или пустое (null или '').
     *
     * @param string $field Имя поля
     * @return bool
     */
    private function isEmpty(string $field): bool
    {
        $value = $this->data[$field] ?? null;

        return $value === null || $value === '';
    }

    /**
     * Запоминает ошибку, если у поля её ещё нет.
     *
     * @param string $field   Имя поля
     * @param string $message Сообщение об ошибке
     * @return void
     */
    private function fail(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    /**
     * Склоняет слово «символ» для сообщений вида «Минимум 5 символов».
     *
     * @param int $count Число
     * @return string
     */
    private function pluralizeChars(int $count): string
    {
        $lastTwo = $count % 100;
        $last = $count % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return 'символов';
        }

        return match (true) {
            $last === 1 => 'символ',
            $last >= 2 && $last <= 4 => 'символа',
            default => 'символов',
        };
    }
}

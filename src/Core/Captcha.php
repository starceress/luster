<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Arithmetic CAPTCHA — generates a simple math question stored in session.
 *
 * The session must already be started before calling any method here.
 * The Session class handles session initialisation for all public and admin
 * request flows.
 *
 * Each form should pass its own $key so that separate tabs / different forms
 * do not overwrite each other's stored answer.
 *
 * Usage:
 *  // GET handler — render the question:
 *  $question = Captcha::getQuestion('join');
 *
 *  // POST handler — validate the answer:
 *  if (!Captcha::validate($request->post('captcha_answer', ''), 'join')) {
 *      // wrong — a fresh question has already been generated
 *  }
 */
final class Captcha
{
    /**
     * Generates a new arithmetic question, stores both the question string and
     * the integer answer in session, and returns both.
     *
     * Operands are in the range 1–12. Subtraction is only chosen when it
     * produces a result >= 1 (the larger operand is always placed first).
     * If both operands are equal the operator is switched to addition so
     * the answer is never zero.
     *
     * @param  string $key  Form identifier — used to scope the session keys so
     *                      different forms (join, update, remove, reset) do not
     *                      share a captcha slot and produce independent questions.
     * @return array{question: string, answer: int}
     */
    public static function generate(string $key = 'default'): array
    {
        $a  = random_int(1, 12);
        $b  = random_int(1, 12);
        $op = random_int(0, 1) === 1 ? '+' : '-';

        if ($op === '-') {
            // Ensure a > b so the result is always >= 1
            if ($a < $b) {
                [$a, $b] = [$b, $a];
            }
            // If equal, switch to addition to prevent a zero answer
            if ($a === $b) {
                $op = '+';
            }
        }

        $answer   = ($op === '+') ? ($a + $b) : ($a - $b);
        $question = "{$a} {$op} {$b}";

        $_SESSION["captcha_{$key}_answer"]   = $answer;
        $_SESSION["captcha_{$key}_question"] = $question;

        return ['question' => $question, 'answer' => $answer];
    }

    /**
     * Validates a submitted answer against the session-stored answer for $key.
     *
     * Always regenerates a fresh question regardless of outcome so that:
     *  - a wrong answer never lets the user retry with the same question, and
     *  - a correct answer cannot be replayed.
     *
     * @param string $key  Must match the key used when the form was rendered.
     */
    public static function validate(string $submitted, string $key = 'default'): bool
    {
        $expected = $_SESSION["captcha_{$key}_answer"] ?? null;
        $correct  = ($expected !== null) && ((int) $submitted === (int) $expected);

        // Regenerate unconditionally — prevents answer reuse
        self::generate($key);

        return $correct;
    }

    /**
     * Generates a fresh captcha question for $key and returns the question string.
     *
     * Always generates a new question on every call so that each page load
     * presents a different challenge to the user.
     *
     * @param string $key  Form identifier — use the same key as validate().
     */
    public static function getQuestion(string $key = 'default'): string
    {
        return self::generate($key)['question'];
    }
}

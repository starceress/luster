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
 * Usage:
 *  // GET handler — render the question:
 *  $question = Captcha::getQuestion();
 *
 *  // POST handler — validate the answer:
 *  if (!Captcha::validate($request->post('captcha_answer', ''))) {
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
     * @return array{question: string, answer: int}
     */
    public static function generate(): array
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

        $_SESSION['captcha_answer']   = $answer;
        $_SESSION['captcha_question'] = $question;

        return ['question' => $question, 'answer' => $answer];
    }

    /**
     * Validates a submitted answer against the session-stored answer.
     *
     * Always regenerates a fresh question regardless of outcome so that:
     *  - a wrong answer never lets the user retry with the same question, and
     *  - a correct answer cannot be replayed.
     */
    public static function validate(string $submitted): bool
    {
        $expected = $_SESSION['captcha_answer'] ?? null;
        $correct  = ($expected !== null) && ((int) $submitted === (int) $expected);

        // Regenerate unconditionally — prevents answer reuse
        self::generate();

        return $correct;
    }

    /**
     * Returns the current captcha question string, generating a fresh one if
     * no question is stored in session yet.
     */
    public static function getQuestion(): string
    {
        if (!isset($_SESSION['captcha_question'])) {
            self::generate();
        }

        return (string) $_SESSION['captcha_question'];
    }
}

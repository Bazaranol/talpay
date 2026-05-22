<?php

use App\Support\EmailMask;

it('masks the local part of an email keeping first two chars', function () {
    expect(EmailMask::mask('alexander@example.com'))->toBe('al*******@example.com');
    expect(EmailMask::mask('ab@example.com'))->toBe('ab*@example.com');
    expect(EmailMask::mask('a@example.com'))->toBe('a*@example.com');
});

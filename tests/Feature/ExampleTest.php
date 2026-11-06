<?php

it('redirects a guest from the root route to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

<?php

test('the home page redirects to login', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

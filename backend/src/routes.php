<?php
declare(strict_types=1);

use App\Auth;
use App\Controllers\{AuthController as A, UserController as U, CourseController as C, AssignmentController as S, MeController as M};

/** @var App\Router $router */
$in = [[Auth::class, 'required']];
$admin = [...$in, Auth::role('administrator')];
$teach = [...$in, Auth::role('instructor', 'administrator')];
$learn = [...$in, Auth::role('learner')];
$p = '/api/v1';

$router->add('POST', "$p/auth/login", [A::class, 'login']);
$router->add('GET',  "$p/auth/me", [A::class, 'me'], $in);
$router->add('POST', "$p/auth/logout", [A::class, 'logout'], $in);

$router->add('GET',  "$p/users", [U::class, 'index'], $admin);
$router->add('POST', "$p/users", [U::class, 'create'], $admin);
$router->add('PATCH', "$p/users/{id}", [U::class, 'setStatus'], $admin);
$router->add('GET',  "$p/audit-logs", [U::class, 'audit'], $admin);

$router->add('GET',  "$p/terms", [C::class, 'terms'], $in);
$router->add('POST', "$p/terms", [C::class, 'createTerm'], $admin);
$router->add('GET',  "$p/courses", [C::class, 'index'], $in);
$router->add('POST', "$p/courses", [C::class, 'create'], $admin);
$router->add('GET',  "$p/courses/{id}", [C::class, 'show'], $in);
$router->add('POST', "$p/courses/{id}/publish", [C::class, 'publish'], $admin);
$router->add('POST', "$p/courses/{id}/sections", [C::class, 'createSection'], $admin);
$router->add('POST', "$p/sections/{id}/enrollments", [C::class, 'enroll'], $admin);

$router->add('GET',  "$p/courses/{id}/modules", [C::class, 'modules'], $in);
$router->add('POST', "$p/courses/{id}/modules", [C::class, 'createModule'], $teach);
$router->add('POST', "$p/modules/{id}/lessons", [C::class, 'createLesson'], $teach);
$router->add('GET',  "$p/lessons/{id}", [C::class, 'lesson'], $in);
$router->add('PUT',  "$p/lessons/{id}/progress", [C::class, 'completeLesson'], $learn);
$router->add('GET',  "$p/courses/{id}/progress", [C::class, 'progress'], $learn);
$router->add('GET',  "$p/courses/{id}/announcements", [C::class, 'announcements'], $in);
$router->add('POST', "$p/courses/{id}/announcements", [C::class, 'createAnnouncement'], $teach);

$router->add('GET',  "$p/courses/{id}/assignments", [S::class, 'index'], $in);
$router->add('POST', "$p/courses/{id}/assignments", [S::class, 'create'], $teach);
$router->add('POST', "$p/assignments/{id}/submissions", [S::class, 'submit'], $learn);
$router->add('GET',  "$p/assignments/{id}/submissions", [S::class, 'submissions'], $teach);
$router->add('POST', "$p/submissions/{id}/grade", [S::class, 'grade'], $teach);
$router->add('GET',  "$p/submissions/{id}/file", [S::class, 'download'], $in);

$router->add('GET',  "$p/me/grades", [M::class, 'grades'], $learn);
$router->add('GET',  "$p/me/notifications", [M::class, 'notifications'], $in);
$router->add('POST', "$p/notifications/{id}/read", [M::class, 'markRead'], $in);

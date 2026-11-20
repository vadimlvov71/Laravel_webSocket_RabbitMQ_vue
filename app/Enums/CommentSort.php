<?php

// app/Enums/CommentSort.php

namespace App\Enums;

enum CommentSort: string
{
    case UserName = 'user_name';
    case Email = 'email';
    case CreatedAt = 'created_at';
}

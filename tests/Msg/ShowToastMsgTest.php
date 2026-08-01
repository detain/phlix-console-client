<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Msg;

use Phlix\Console\Msg\ShowToastMsg;
use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\ToastType;

final class ShowToastMsgTest extends TestCase
{
    public function testErrorCreatesErrorToast(): void
    {
        $msg = ShowToastMsg::error('something went wrong');

        self::assertSame(ToastType::Error, $msg->type);
        self::assertSame('something went wrong', $msg->message);
    }

    public function testWarningCreatesWarningToast(): void
    {
        $msg = ShowToastMsg::warning('be careful');

        self::assertSame(ToastType::Warning, $msg->type);
        self::assertSame('be careful', $msg->message);
    }

    public function testInfoCreatesInfoToast(): void
    {
        $msg = ShowToastMsg::info('here is some information');

        self::assertSame(ToastType::Info, $msg->type);
        self::assertSame('here is some information', $msg->message);
    }

    public function testSuccessCreatesSuccessToast(): void
    {
        $msg = ShowToastMsg::success('it worked');

        self::assertSame(ToastType::Success, $msg->type);
        self::assertSame('it worked', $msg->message);
    }

    public function testToStringIsMessage(): void
    {
        $msg = ShowToastMsg::info('test message');

        self::assertSame('test message', $msg->message);
    }
}

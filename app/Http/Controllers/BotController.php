<?php

namespace App\Http\Controllers;

use App\Mail\SendCode;
use App\Mail\SendMessage;
use App\Models\Company;
use App\Models\Step;
use App\Models\User;
use App\Models\Worker;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BotController extends Controller
{
    private string $telegramApiUrl;

    public function __construct()
    {
        $this->telegramApiUrl = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
    }

    private function store($chatId, $text, $replyMarkup = null)
    {
        try {
            // Verify chat existence first
            $response = Http::get($this->telegramApiUrl . '/getChat', [
                'chat_id' => $chatId
            ]);

            if (!$response->successful()) {
                Log::error('Chat verification failed', [
                    'chat_id' => $chatId,
                    'response' => $response->body()
                ]);
                return;
            }

            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::post($this->telegramApiUrl . '/sendMessage', $payload);

            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'chat_id' => $chatId
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to send message to Telegram', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);
        }
    }

    public function webhook(Request $request)
    {
        try {
            $update = $request->all();
            Log::info(['Webhook received update:', $update]);

            if (isset($update['callback_query'])) {
                $callbackQuery = $update['callback_query'];
                $this->handleCallbackQuery($callbackQuery);
                return response()->json(['status' => 'success']);
            }

            if (isset($update['message'])) {
                $chatId = $update['message']['chat']['id'];
                $text = $update['message']['text'] ?? '';
                $photo = $update['message']['photo'] ?? null;
                $messageId = $update['message']['message_id'] ?? null;

                Log::info('Processing message:', [
                    'chat_id' => $chatId,
                    'text' => $text
                ]);

                $step = Step::firstOrCreate(['chat_id' => $chatId], ['step' => 'start']);
                Log::info('Current step:', ['step' => $step->step]);

                switch ($step->step) {
                    case 'start':
                        if ($text === '/start') {
                            $this->store($chatId, 'Welcome! Please choose an option:', [
                                'keyboard' => [
                                    [['text' => 'Register'], ['text' => 'Login']],
                                ],
                                'resize_keyboard' => true,
                                'one_time_keyboard' => true,
                            ]);
                        } elseif ($text === 'Register') {
                            $step->step = 'choose_role';
                            $step->save();
                            $this->store($chatId, 'Choose your role:', [
                                'keyboard' => [
                                    [['text' => 'Company holder'], ['text' => 'Employee of company']],
                                ],
                                'resize_keyboard' => true,
                                'one_time_keyboard' => true,
                            ]);
                        } elseif ($text === 'Login') {
                            $step->step = 'login_email';
                            $step->save();
                            $this->store($chatId, 'Please enter your email:', [
                                'remove_keyboard' => true
                            ]);
                        }
                        break;

                    case 'choose_role':
                        if ($text === 'Company holder') {
                            $step->role = 'holder';
                            $step->step = 'company_name';
                            $step->save();
                            $this->store($chatId, 'Please enter your company name:', [
                                'remove_keyboard' => true
                            ]);
                        } elseif ($text === 'Employee of company') {
                            $step->role = 'employee';
                            $step->step = 'company_selection';
                            $step->save();
                            $this->store($chatId, 'Please enter the company name you want to join:', [
                                'remove_keyboard' => true
                            ]);
                        }
                        break;

                        // In your webhook method, add debugging for company name
                    case 'company_name':
                        if (strlen($text) < 2) {
                            $this->store($chatId, 'Company name must be at least 2 characters long.');
                            return;
                        }
                        Log::info('Setting company name in step:', ['company_name' => $text]);
                        $step->company_name = $text;
                        $step->step = 'user_name';
                        $step->save();
                        $this->store($chatId, 'Please enter your full name:');
                        break;

                    case 'company_selection':
                        if (strlen($text) < 2) {
                            $this->store($chatId, 'Company name must be at least 2 characters long.');
                            return;
                        }
                        $company = Company::where('name', $text)->first();
                        if (!$company) {
                            $this->store($chatId, 'Company not found. Please check the name and try again.');
                            return;
                        }
                        $step->company_name = $text;
                        $step->step = 'user_name';
                        $step->save();
                        $this->store($chatId, 'Please enter your full name:');
                        break;

                    case 'user_name':
                        if (strlen($text) < 2) {
                            $this->store($chatId, 'Name must be at least 2 characters long.');
                            return;
                        }
                        $step->name = $text;
                        $step->step = 'email';
                        $step->save();
                        $this->store($chatId, 'Please enter your email address:');
                        break;

                    case 'email':
                        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                            $this->store($chatId, 'Invalid email format. Please try again.');
                            return;
                        }

                        if (User::where('email', $text)->exists()) {
                            $this->store($chatId, 'This email is already registered. Please use a different email.');
                            Log::info('Duplicate email registration attempt', ['email' => $text, 'chatId' => $chatId]);
                            return;
                        }

                        $step->email = $text;
                        $step->step = 'password';
                        $step->save();

                        $this->store($chatId, 'Please enter your password (minimum 6 characters):');
                        break;


                    case 'password':
                        if (strlen($text) < 6) {
                            $this->store($chatId, 'Password must be at least 6 characters long.');
                            return;
                        }

                        $confirmation_code = Str::random(6);
                        try {
                            Mail::to($step->email)->send(new SendMessage($confirmation_code));
                            $step->confirmation_code = $confirmation_code;
                            $step->password = bcrypt($text);
                            $step->step = 'confirmation';
                            $step->save();
                            $this->store($chatId, 'A confirmation code has been sent to your email. Please enter it here:');
                        } catch (Exception $e) {
                            Log::error('Failed to send confirmation email:', ['error' => $e->getMessage()]);
                            $this->store($chatId, 'Failed to send confirmation email. Please try again.');
                        }
                        break;

                    case 'confirmation':
                        if ($text !== $step->confirmation_code) {
                            $this->store($chatId, 'Invalid confirmation code. Please try again.');
                            return;
                        }
                        $step->step = 'image';
                        $step->save();
                        $this->store($chatId, 'Please send your profile photo:');
                        break;

                    case 'image':
                        if (!$photo) {
                            $this->store($chatId, 'Please send a photo.');
                            return;
                        }

                        $fileId = end($photo)['file_id'];
                        $response = Http::get("{$this->telegramApiUrl}/getFile", [
                            'file_id' => $fileId
                        ]);

                        if ($response->successful()) {
                            $filePath = $response->json()['result']['file_path'];
                            $imageContent = file_get_contents("https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/{$filePath}");
                            $imageName = uniqid() . '.jpg';

                            if (Storage::disk('public')->put("uploads/{$imageName}", $imageContent)) {
                                $this->completeRegistration($step, "uploads/{$imageName}");
                            } else {
                                $this->store($chatId, 'Failed to save image. Please try again.');
                            }
                        } else {
                            $this->store($chatId, 'Failed to process image. Please try again.');
                        }
                        break;

                    case 'login_email':
                        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                            $this->store($chatId, 'Invalid email format. Please try again.');
                            return;
                        }
                        $step->email = $text;
                        $step->step = 'login_password';
                        $step->save();
                        $this->store($chatId, 'Please enter your password:');
                        break;

                    case 'login_password':
                        $user = User::where('email', $step->email)->first();
                        if ($user && password_verify($text, $user->password)) {
                            $user->chat_id = $chatId;
                            $user->save();
                            $step->delete();
                            $this->store($chatId, "Welcome back, {$user->name}!");
                        } else {
                            $this->store($chatId, 'Invalid email or password. Please try again.');
                        }
                        break;

                    default:
                        $this->store($chatId, "Sorry, I didn't understand that. Use /start to begin.");
                        break;
                }
            }

            return response()->json(['status' => 'success']);
        } catch (Exception $e) {
            Log::error('Webhook error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    private function handleCallbackQuery($callbackQuery)
    {
        $data = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];

        $parts = explode('_', $data);
        $action = $parts[0];
        $type = $parts[1] ?? '';
        $userId = $parts[2] ?? '';

        try {
            if ($action === 'approve') {
                if ($type === 'worker') {
                    $this->approveWorker($userId, $chatId, $messageId);
                } else {
                    $this->approveCompany($userId, $chatId, $messageId);
                }
            } elseif ($action === 'reject') {
                if ($type === 'worker') {
                    $this->rejectWorker($userId, $chatId, $messageId);
                } else {
                    $this->rejectCompany($userId, $chatId, $messageId);
                }
            }
        } catch (Exception $e) {
            Log::error('Callback query handling failed:', ['error' => $e->getMessage()]);
            $this->store($chatId, 'Failed to process the request. Please try again.');
        }
    }

    private function completeRegistration(Step $step, string $imagePath)
    {
        try {
            DB::beginTransaction();

            Log::info('Starting registration with data:', [
                'step_data' => $step->toArray(),
                'role' => $step->role,
                'company_name' => $step->company_name
            ]);

            $userData = [
                'name' => $step->name,
                'email' => $step->email,
                'password' => $step->password,
                'chat_id' => $step->chat_id,
                'image' => $imagePath,
                'email_verified_at' => Carbon::now(),
                'status' => 'pending',
                'company' => $step->company_name  // Explicitly set company name
            ];

            if ($step->role === 'holder') {
                $userData['role'] = 'holder';

                Log::info('Creating holder user with data:', $userData);

                $user = User::create($userData);

                // Notify admin
                $admin = User::where('role', 'admin')->first();
                if ($admin) {
                    $this->notifyAdmin($admin, $user);
                }
            } else {
                $userData['role'] = 'employee';

                Log::info('Creating employee user with data:', $userData);

                $user = User::create($userData);

                // Notify company holder
                $holder = User::where('role', 'holder')
                    ->where('company', $step->company_name)
                    ->first();

                if ($holder) {
                    $this->notifyHolder($holder, $user);
                }
            }

            $this->store($step->chat_id, 'Registration successful! Please wait for approval.');
            $step->delete();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Registration completion failed:', [
                'error' => $e->getMessage(),
                'step_data' => $step->toArray()
            ]);
            $this->store($step->chat_id, 'Registration failed. Please try again.');
        }
    }




    private function editMessage($chatId, $messageId, $newText, $replyMarkup = null)
    {
        try {
            $payload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $newText,
                'parse_mode' => 'HTML'
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::post($this->telegramApiUrl . '/editMessageText', $payload);

            if (!$response->successful()) {
                Log::error('Failed to edit Telegram message', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]);
                throw new Exception('Failed to edit message: ' . $response->body());
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error('Message editing failed', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
            throw $e;
        }
    }

    // Example usage in approval methods:
    private function approveCompany($userId, $chatId, $messageId)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($userId);

            Log::info('Approving company for user:', [
                'user_id' => $userId,
                'user_data' => $user->toArray()
            ]);

            if (empty($user->company)) {
                throw new ValidationException('Company name is required for approval');
            }

            $user->status = 'approved';
            $user->save();

            // Create company record
            $company = Company::create([
                'name' => $user->company,
                'email' => $user->email,
                'owner_id' => $user->id,
                'status' => 'active'
            ]);

            Log::info('Company created:', $company->toArray());

            $newMessage = "✅ <b>Company Registration Approved</b>\n\n" .
                "Company: {$user->company}\n" .
                "Owner: {$user->name}\n" .
                "Status: Approved\n" .
                "Date: " . now()->format('Y-m-d H:i:s');

            $this->editMessage($chatId, $messageId, $newMessage);

            $this->store(
                $user->chat_id,
                "🎉 <b>Congratulations!</b>\n\n" .
                    "Your company registration for <b>{$user->company}</b> has been approved.\n" .
                    "You can now start using the system."
            );

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Company approval failed:', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'user_data' => $user ?? null
            ]);
            throw $e;
        }
    }



    private function rejectCompany($userId, $chatId, $messageId)
    {
        $user = User::findOrFail($userId);

        $user->status = 'rejected';
        $user->save();

        $newMessage = "❌ <b>Company Registration Rejected</b>\n\n" .
            "Company: {$user->company}\n" .
            "Owner: {$user->name}\n" .
            "Status: Rejected\n" .
            "Date: " . now()->format('Y-m-d H:i:s');

        $this->editMessage($chatId, $messageId, $newMessage);

        // Notify the company owner
        $this->store(
            $user->chat_id,
            "❌ <b>Registration Update</b>\n\n" .
                "Your company registration for <b>{$user->company}</b> has been rejected.\n" .
                "Please contact support for more information."
        );
    }

    private function approveWorker($userId, $chatId, $messageId)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($userId);
            $worker = Worker::where('user_id', $userId)->first();

            if (!$worker) {
                throw new Exception('Worker record not found');
            }

            // Update user status
            $user->status = 'approved';
            $user->save();

            // Update worker status
            $worker->status = 'approved';
            $worker->save();

            // Edit the original message
            $newMessage = "✅ <b>Worker Registration Approved</b>\n\n" .
                "Name: {$user->name}\n" .
                "Company: {$user->company}\n" .
                "Status: Approved\n" .
                "Date: " . now()->format('Y-m-d H:i:s');

            $this->editMessage($chatId, $messageId, $newMessage);

            // Notify the worker
            $this->store(
                $user->chat_id,
                "🎉 <b>Congratulations!</b>\n\n" .
                    "Your registration as a worker at <b>{$user->company}</b> has been approved.\n" .
                    "You can now start using the system."
            );

            DB::commit();
            Log::info('Worker approved:', ['user_id' => $userId, 'chat_id' => $chatId]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Worker approval failed:', ['error' => $e->getMessage(), 'user_id' => $userId]);
            throw $e;
        }
    }

    private function rejectWorker($userId, $chatId, $messageId)
    {
        try {
            $user = User::findOrFail($userId);

            $user->status = 'rejected';
            $user->save();

            // Edit the original message to remove buttons and show status
            $newMessage = "❌ <b>Worker Registration Rejected</b>\n\n" .
                "Name: {$user->name}\n" .
                "Company: {$user->company}\n" .
                "Status: Rejected\n" .
                "Date: " . now()->format('Y-m-d H:i:s');

            $this->editMessage($chatId, $messageId, $newMessage);

            // Notify the worker
            $this->store(
                $user->chat_id,
                "❌ <b>Registration Update</b>\n\n" .
                    "Your registration as a worker at <b>{$user->company}</b> has been rejected.\n" .
                    "Please contact the company for more information."
            );

            Log::info('Worker rejected:', ['user_id' => $userId, 'chat_id' => $chatId]);
        } catch (Exception $e) {
            Log::error('Worker rejection failed:', ['error' => $e->getMessage(), 'user_id' => $userId]);
            throw $e;
        }
    }
    private function notifyAdmin($admin, $user)
    {
        // Check if admin chat_id exists and is valid
        if (!$admin->chat_id) {
            Log::warning('Admin notification failed: No chat ID available', [
                'admin_id' => $admin->id,
                'user_id' => $user->id
            ]);
            return;
        }

        try {
            // Verify chat existence first
            $response = Http::get($this->telegramApiUrl . '/getChat', [
                'chat_id' => $admin->chat_id
            ]);

            if (!$response->successful()) {
                Log::error('Admin chat verification failed', [
                    'admin_id' => $admin->id,
                    'chat_id' => $admin->chat_id,
                    'response' => $response->body()
                ]);
                return;
            }

            $message = "🆕 <b>New Company Registration Request</b>\n\n" .
                "Company: <b>{$user->company}</b>\n" .
                "Owner: {$user->name}\n" .
                "Email: {$user->email}\n" .
                "Date: " . now()->format('Y-m-d H:i:s');

            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Approve',
                            'callback_data' => "approve_company_{$user->id}"
                        ],
                        [
                            'text' => '❌ Reject',
                            'callback_data' => "reject_company_{$user->id}"
                        ]
                    ]
                ]
            ];

            $this->store($admin->chat_id, $message, $keyboard);
        } catch (Exception $e) {
            Log::error('Admin notification failed', [
                'error' => $e->getMessage(),
                'admin_id' => $admin->id,
                'user_id' => $user->id
            ]);
        }
    }

    private function notifyHolder($holder, $user)
    {
        // Check if holder chat_id exists and is valid
        if (!$holder->chat_id) {
            Log::warning('Holder notification failed: No chat ID available', [
                'holder_id' => $holder->id,
                'user_id' => $user->id
            ]);
            return;
        }

        try {
            // Verify chat existence first
            $response = Http::get($this->telegramApiUrl . '/getChat', [
                'chat_id' => $holder->chat_id
            ]);

            if (!$response->successful()) {
                Log::error('Holder chat verification failed', [
                    'holder_id' => $holder->id,
                    'chat_id' => $holder->chat_id,
                    'response' => $response->body()
                ]);
                return;
            }

            $message = "🆕 <b>New Worker Registration Request</b>\n\n" .
                "Name: {$user->name}\n" .
                "Email: {$user->email}\n" .
                "Company: <b>{$user->company}</b>\n" .
                "Date: " . now()->format('Y-m-d H:i:s');

            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Approve',
                            'callback_data' => "approve_worker_{$user->id}"
                        ],
                        [
                            'text' => '❌ Reject',
                            'callback_data' => "reject_worker_{$user->id}"
                        ]
                    ]
                ]
            ];

            $this->store($holder->chat_id, $message, $keyboard);
        } catch (Exception $e) {
            Log::error('Holder notification failed', [
                'error' => $e->getMessage(),
                'holder_id' => $holder->id,
                'user_id' => $user->id
            ]);
        }
    }
}
